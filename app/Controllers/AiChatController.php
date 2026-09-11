<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiRouter;
use App\AI\AiUnavailableException;
use App\AI\JsonStream;
use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\CourseRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\TextExtract;
use App\Support\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use RuntimeException;
use Throwable;

/**
 * ช่องสนทนาลอยกับผู้ช่วย AI — ถามได้ทุกหน้า และครูสั่งให้ช่วยสร้างรายวิชาได้
 * ไม่ตัดโควตาของส่วนกลาง แต่บันทึกการใช้งานตามจริง และงานที่เขียนข้อมูลต้องให้ครูกดยืนยันก่อนเสมอ
 */
final class AiChatController
{
    /** ความยาวข้อความสูงสุดต่อครั้ง — ยาวพอให้ครูวางคำอธิบายรายวิชาทั้งหน้าได้ */
    private const MAX_MESSAGE = 8000;

    public function __construct(
        private readonly AiRouter $router,
        private readonly AiRepository $ai,
        private readonly Auth $auth,
        private readonly SettingsRepository $settings,
        private readonly CourseRepository $courses,
    ) {
    }

    /** สถานะเส้นทาง AI ปัจจุบัน สำหรับแสดง chip ตอนเปิดช่องสนทนา */
    public function status(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        try {
            $quality = $this->settings->userQualityPref((int) $user['id']);
            $route = $this->router->route((int) $user['id'], $quality, false, real: true);

            return $this->json($response, [
                'ok' => true,
                'chip' => $route->chip,
                'source' => $route->source,
                'model' => $route->model,
            ]);
        } catch (AiUnavailableException $e) {
            return $this->json($response, [
                'ok' => false,
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function stream(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $params = $request->getQueryParams();

        if (!Csrf::check($params['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        // ข้อความมาจาก prepare() ผ่านเซสชัน (ใช้ครั้งเดียวแล้วทิ้ง) — ไม่ผ่าน URL เพราะยาวเกินขีดจำกัด
        $prompt = $this->pullPrompt((string) ($params['p'] ?? ''));
        $message = trim($prompt['message'] !== '' ? $prompt['message'] : (string) ($params['q'] ?? ''));
        $attachIds = $prompt['attach'] !== '' ? $prompt['attach'] : (string) ($params['attach'] ?? '');

        if ($message === '' && $attachIds === '') {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้พิมพ์ข้อความ'], 422);
        }
        $message = mb_substr($message, 0, self::MAX_MESSAGE);

        // ต่อเนื้อหาจากไฟล์ที่ครูแนบไว้เข้าไปเป็นบริบท ก่อนปิดเซสชัน
        $attached = $this->attachedText($attachIds);

        // ปล่อยล็อกเซสชันก่อนสตรีม ไม่งั้นหน้าอื่นจะค้างรอจนกว่าจะตอบจบ
        session_write_close();

        ignore_user_abort(true);
        @set_time_limit(300);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $send = static function (string $event, array $data): void {
            echo "event: $event\n" . 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };

        $quality = $this->settings->userQualityPref((int) $user['id']);

        try {
            $route = $this->router->route((int) $user['id'], $quality, false, real: true);
        } catch (AiUnavailableException $e) {
            $send('error', ['reason' => $e->reason, 'message' => $e->getMessage()]);

            return $response;
        }

        $send('meta', ['chip' => $route->chip, 'source' => $route->source]);

        $spec = json_encode([
            'task' => 'chat',
            'message' => $attached === '' ? $message : $attached . "\n\n---\n\nคำถามหรือคำสั่งของครู:\n" . $message,
        ], JSON_UNESCAPED_UNICODE);
        $isTeacher = ($user['role'] ?? '') === 'teacher';

        $chars = 0;
        $mode = null;      // null = ยังไม่รู้ว่าเป็นข้อความหรือคำสั่ง, 'text', 'action'
        $pending = '';     // ข้อความต้นเรื่องที่พักไว้ระหว่างตัดสินใจ
        $rawAction = '';

        try {
            foreach ($route->provider->stream($this->systemPrompt($isTeacher), $spec, $quality) as $chunk) {
                $chunk = (string) $chunk;
                if ($chunk === '') {
                    continue;
                }

                if ($mode === 'action') {
                    $rawAction .= $chunk;

                    continue;
                }

                $pending .= $chunk;

                if ($mode === null) {
                    $probe = preg_replace('/^```(?:json)?\s*/i', '', ltrim($pending)) ?? '';
                    if ($probe === '') {
                        continue;
                    }
                    if (str_starts_with($probe, '{')) {
                        $mode = 'action';
                        $rawAction = $probe;
                        $send('working', ['message' => 'กำลังเตรียมรายวิชาให้…']);

                        continue;
                    }
                    // ไม่ใช่ JSON แน่แล้ว — ปล่อยข้อความที่พักไว้ออกไปทั้งหมด
                    $mode = 'text';
                }

                $chars += mb_strlen($pending);
                $send('delta', ['text' => $pending]);
                $pending = '';
            }

            if ($mode === 'action') {
                $action = $this->parseAction($rawAction, $isTeacher);
                if ($action !== null) {
                    $send('action', $action);
                } else {
                    // โมเดลตอบเป็น JSON แต่ใช้ไม่ได้ — แสดงเป็นข้อความไปตามตรง
                    $chars += mb_strlen($rawAction);
                    $send('delta', ['text' => $rawAction]);
                }
            } elseif ($pending !== '') {
                $chars += mb_strlen($pending);
                $send('delta', ['text' => $pending]);
            }
        } catch (AiUnavailableException $e) {
            // ข้อผิดพลาดจากบริการจริง (รหัสหมดอายุ โควตาผู้ให้บริการ ฯลฯ) — บอกสาเหตุตามจริง
            $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, false);
            $send('error', ['reason' => $e->reason, 'message' => $e->getMessage()]);

            return $response;
        } catch (Throwable $e) {
            $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, false);
            $send('error', ['reason' => 'generate_failed', 'message' => 'ตอบกลับไม่สำเร็จ กรุณาลองใหม่']);

            return $response;
        }

        $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, $chars > 0);
        $this->auth->log('ai.chat.test', null, ['source' => $route->source, 'quality' => $quality, 'chars' => $chars]);

        $send('done', ['chars' => $chars]);

        return $response;
    }

    /**
     * รับข้อความที่ครูพิมพ์มาเก็บไว้ในเซสชันก่อน แล้วคืน id ให้หน้าจอไปเปิดสตรีมต่อ
     *
     * EventSource เปิดได้เฉพาะ GET และข้อความภาษาไทยยาว ๆ จะทำให้ URL เกินขีดจำกัดของเว็บเซิร์ฟเวอร์
     * (ไทย 1 ตัวอักษร = 9 ไบต์เมื่อ encode ใส่ URL ทำให้ 1,000 ตัวอักษรก็ชน 414 URI Too Long แล้ว)
     */
    public function prepare(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $message = trim((string) ($data['q'] ?? ''));
        $attach = trim((string) ($data['attach'] ?? ''));

        if ($message === '' && $attach === '') {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้พิมพ์ข้อความ'], 422);
        }

        $id = bin2hex(random_bytes(8));
        $prompts = $_SESSION['ai_chat_prompts'] ?? [];
        $prompts[$id] = [
            'message' => mb_substr($message, 0, self::MAX_MESSAGE),
            'attach' => $attach,
        ];

        // เก็บไว้แค่ 3 รายการล่าสุด กันเซสชันบวมเมื่อครูพิมพ์ยาวหลายรอบ
        $_SESSION['ai_chat_prompts'] = array_slice($prompts, -3, 3, true);

        return $this->json($response, ['ok' => true, 'id' => $id]);
    }

    /**
     * รับไฟล์แนบ ดึงข้อความออกมาเก็บไว้ในเซสชัน แล้วคืนสรุปให้หน้าจอแสดงเป็นชิป
     * (เก็บไว้แค่ 3 ไฟล์ล่าสุดต่อผู้ใช้ เพื่อไม่ให้เซสชันบวม)
     */
    public function attach(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $file = ($request->getUploadedFiles()['file'] ?? null);
        if (!$file instanceof UploadedFileInterface) {
            return $this->json($response, ['ok' => false, 'message' => 'ยังไม่ได้เลือกไฟล์'], 422);
        }

        try {
            $text = TextExtract::fromUpload($file);
        } catch (RuntimeException $e) {
            return $this->json($response, ['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $id = bin2hex(random_bytes(8));
        $name = mb_substr((string) $file->getClientFilename(), 0, 120);

        $_SESSION['ai_chat_files'] = array_slice(
            ($_SESSION['ai_chat_files'] ?? []) + [],
            -2,
            2,
            true
        ) + [$id => ['name' => $name, 'text' => $text]];

        return $this->json($response, [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'chars' => mb_strlen($text),
            'preview' => mb_substr($text, 0, 160),
        ]);
    }

    /** ครูกดยืนยันจากการ์ดในช่องสนทนา แล้วระบบจึงสร้างรายวิชาให้จริง */
    public function createCourse(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($code === '' || $name === '') {
            return $this->json($response, ['ok' => false, 'message' => 'ข้อมูลรายวิชาไม่ครบ'], 422);
        }

        $termId = $this->courses->currentTermId();
        if ($this->courses->codeTaken($code, $termId, null, null)) {
            return $this->json($response, [
                'ok' => false,
                'message' => 'มีรายวิชารหัส ' . $code . ' ในภาคเรียนนี้อยู่แล้ว',
            ], 409);
        }

        $id = $this->courses->create((int) $user['id'], [
            'code' => $code,
            'name' => $name,
            'credits' => max(0, min(9.9, (float) ($data['credits'] ?? 3))),
            'theory_hours' => max(0, min(255, (int) ($data['theory_hours'] ?? 1))),
            'practice_hours' => max(0, min(255, (int) ($data['practice_hours'] ?? 2))),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'term_id' => $termId,
            'classroom_id' => null,
        ]);

        $this->auth->log('ai.chat.create_course', 'course#' . $id, ['code' => $code]);

        return $this->json($response, [
            'ok' => true,
            'id' => $id,
            'name' => $name,
            'url' => Url::to('/courses/' . $id),
            'editUrl' => Url::to('/courses/' . $id . '/edit'),
            'message' => 'สร้างรายวิชา ' . $name . ' แล้ว',
        ]);
    }

    /**
     * ดึงข้อความที่ prepare() เก็บไว้ออกมาใช้ แล้วลบทิ้งทันที (ใช้ได้ครั้งเดียว)
     *
     * @return array{message:string,attach:string}
     */
    private function pullPrompt(string $id): array
    {
        $id = trim($id);
        $stored = $_SESSION['ai_chat_prompts'][$id] ?? null;

        if ($id === '' || !is_array($stored)) {
            return ['message' => '', 'attach' => ''];
        }

        unset($_SESSION['ai_chat_prompts'][$id]);

        return [
            'message' => (string) ($stored['message'] ?? ''),
            'attach' => (string) ($stored['attach'] ?? ''),
        ];
    }

    /** ข้อความจากไฟล์แนบที่เก็บไว้ในเซสชัน — รับได้หลายไฟล์ คั่นด้วยจุลภาค */
    private function attachedText(string $ids): string
    {
        $files = $_SESSION['ai_chat_files'] ?? [];
        if ($ids === '' || $files === []) {
            return '';
        }

        $parts = [];
        foreach (array_slice(explode(',', $ids), 0, 3) as $id) {
            $file = $files[trim($id)] ?? null;
            if ($file !== null) {
                $parts[] = sprintf("เนื้อหาจากไฟล์ \"%s\" ที่ครูแนบมา:\n%s", $file['name'], $file['text']);
            }
        }

        return implode("\n\n", $parts);
    }

    /** คำสั่งระบบของช่องสนทนา — ครูเท่านั้นที่สั่งให้สร้างรายวิชาได้ */
    private function systemPrompt(bool $isTeacher): string
    {
        $base = 'คุณเป็นผู้ช่วยครูอาชีวศึกษาในระบบ RVC Learn ตอบสั้น กระชับ เป็นภาษาไทย';

        if (!$isTeacher) {
            return $base;
        }

        return $base . "\n"
            . 'ถ้าครูแนบไฟล์มา ให้ใช้ข้อมูลในไฟล์นั้นตอบคำถามหรือเติมข้อมูลรายวิชาให้ครบที่สุด' . "\n"
            . 'ถ้าครูขอให้สร้างหรือเพิ่มรายวิชา ให้ตอบกลับเป็น JSON บรรทัดเดียวเท่านั้น '
            . 'ห้ามมีข้อความอื่นนำหน้าหรือต่อท้าย และห้ามครอบด้วย ``` ตามรูปแบบนี้ '
            . '{"action":"create_course","code":"20127-2002","name":"ชื่อวิชา","credits":3,"theory_hours":1,"practice_hours":2,"description":"คำอธิบายรายวิชา 1-2 ประโยค"} '
            . 'ถ้าครูไม่ได้บอกรหัสวิชา ให้ตั้งรหัสที่สมเหตุสมผลตามรูปแบบรหัสวิชาอาชีวศึกษา '
            . 'คำขออื่นนอกจากนี้ให้ตอบเป็นข้อความปกติ ห้ามตอบเป็น JSON';
    }

    /**
     * แปลงคำตอบที่เป็น JSON ให้เป็นคำสั่งที่หน้าจอเอาไปทำการ์ดยืนยัน
     *
     * @return array<string,mixed>|null
     */
    private function parseAction(string $raw, bool $isTeacher): ?array
    {
        if (!$isTeacher) {
            return null;
        }

        foreach (JsonStream::objects([$raw]) as $obj) {
            if (($obj['action'] ?? '') !== 'create_course') {
                continue;
            }

            $code = trim((string) ($obj['code'] ?? ''));
            $name = trim((string) ($obj['name'] ?? ''));
            if ($code === '' || $name === '') {
                return null;
            }

            return [
                'action' => 'create_course',
                'code' => mb_substr($code, 0, 32),
                'name' => mb_substr($name, 0, 191),
                'credits' => (float) ($obj['credits'] ?? 3),
                'theory_hours' => (int) ($obj['theory_hours'] ?? 1),
                'practice_hours' => (int) ($obj['practice_hours'] ?? 2),
                'description' => mb_substr(trim((string) ($obj['description'] ?? '')), 0, 500),
            ];
        }

        return null;
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }
}
