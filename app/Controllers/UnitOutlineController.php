<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiRouter;
use App\AI\AiUnavailableException;
use App\AI\JsonStream;
use App\Auth\Auth;
use App\Controllers\Concerns\OwnsCourse;
use App\Domain\AiRepository;
use App\Domain\CourseRepository;
use App\Domain\SettingsRepository;
use App\Domain\UnitRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Throwable;

/**
 * ให้ผู้ช่วย AI ออกแบบ "รายชื่อหน่วยการเรียน" ของทั้งรายวิชาให้ครอบคลุมคำอธิบายรายวิชา
 *
 * ทำงานสามจังหวะ: เปิดฟอร์ม → ร่างรายการมาให้ตรวจ (เก็บไว้ในเซสชัน ยังไม่เขียนฐานข้อมูล)
 * → ครูติ๊กเลือกหน่วยที่ต้องการแล้วกดบันทึก จึงสร้างเป็นหน่วยฉบับร่างให้แก้ต่อ
 */
final class UnitOutlineController
{
    use OwnsCourse;

    private const SESSION_KEY = 'unit_outline';
    private const MAX_UNITS = 20;

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly UnitRepository $units,
        private readonly AiRepository $ai,
        private readonly AiRouter $router,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
    ) {
    }

    protected function courseRepo(): CourseRepository
    {
        return $this->courses;
    }

    public function form(Request $request, Response $response, array $args): Response
    {
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $draft = $_SESSION[self::SESSION_KEY] ?? null;
        $proposed = is_array($draft) && (int) ($draft['course_id'] ?? 0) === (int) $course['id']
            ? (array) $draft['units']
            : [];

        return $this->view->render($response, 'units/outline', [
            'page' => 'courses',
            'course' => $course,
            'existing' => $this->units->forCourse((int) $course['id']),
            'proposed' => $proposed,
            'count' => is_array($draft) ? (int) ($draft['count'] ?? 8) : 8,
            'note' => is_array($draft) ? (string) ($draft['note'] ?? '') : '',
        ]);
    }

    /** เรียกผู้ช่วยให้ร่างรายชื่อหน่วย แล้วพักผลไว้ในเซสชันเพื่อให้ครูตรวจก่อน */
    public function generate(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response, $course);
        }

        $count = max(2, min(self::MAX_UNITS, (int) ($data['count'] ?? 8)));
        $note = mb_substr(trim((string) ($data['note'] ?? '')), 0, 500);
        $existing = array_map(
            static fn (array $u): string => (string) $u['title'],
            $this->units->forCourse((int) $course['id'])
        );

        $spec = json_encode([
            'task' => 'unit_outline',
            'course_code' => $course['code'],
            'course_name' => $course['name'],
            'description' => $course['description'],
            'existing' => $existing,
            'count' => $count,
            'note' => $note,
        ], JSON_UNESCAPED_UNICODE);

        $quality = $this->settings->userQualityPref((int) $user['id']);
        set_time_limit(0);

        try {
            $route = $this->router->route((int) $user['id'], $quality, false, real: true);
            $units = $this->collect($route->provider->stream('', (string) $spec, $quality), $count);
        } catch (AiUnavailableException $e) {
            Flash::error($e->getMessage());

            return $this->back($response, $course);
        } catch (Throwable) {
            Flash::error('ผู้ช่วยร่างรายการหน่วยไม่สำเร็จ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response, $course);
        }

        $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, $units !== []);

        if ($units === []) {
            Flash::error('ผู้ช่วยไม่ได้ร่างหน่วยการเรียนออกมา กรุณาลองใหม่หรือปรับคำสั่งเพิ่มเติม');

            return $this->back($response, $course);
        }

        $_SESSION[self::SESSION_KEY] = [
            'course_id' => (int) $course['id'],
            'units' => $units,
            'count' => $count,
            'note' => $note,
        ];
        $this->auth->log('ai.unit_outline', 'course#' . $course['id'], ['units' => count($units)]);

        return $this->back($response, $course);
    }

    /** บันทึกหน่วยที่ครูติ๊กเลือกเป็นฉบับร่าง */
    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId']);
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->back($response, $course);
        }

        $draft = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($draft) || (int) ($draft['course_id'] ?? 0) !== (int) $course['id']) {
            Flash::error('ไม่พบรายการที่ร่างไว้ กรุณาให้ผู้ช่วยร่างใหม่อีกครั้ง');

            return $this->back($response, $course);
        }

        $picked = array_map('intval', (array) ($data['pick'] ?? []));
        $created = 0;
        $sortOrder = $this->units->nextSortOrder((int) $course['id']);

        foreach ((array) $draft['units'] as $i => $unit) {
            if (!in_array($i, $picked, true)) {
                continue;
            }

            $this->units->create([
                'course_id' => (int) $course['id'],
                'title' => $unit['title'],
                'key_content' => $unit['key_content'] ?: null,
                'objectives' => $unit['objectives'] ?: null,
                'competencies' => $unit['competencies'] ?: null,
                'sort_order' => $sortOrder++,
                'source' => 'ai',
                'review_status' => 'draft',
                'created_by' => (int) $user['id'],
            ]);
            $created++;
        }

        unset($_SESSION[self::SESSION_KEY]);

        if ($created === 0) {
            Flash::warning('ยังไม่ได้เลือกหน่วยการเรียนที่จะบันทึก');

            return $this->back($response, $course);
        }

        $this->auth->log('ai.unit_outline.save', 'course#' . $course['id'], ['created' => $created]);
        Flash::success('บันทึกหน่วยการเรียนเป็นฉบับร่าง ' . $created . ' หน่วยแล้ว · เปิดแก้ไขเพื่อใส่เนื้อหาแล้วเผยแพร่');

        return $response
            ->withHeader('Location', Url::to('/courses/' . $course['id'] . '?tab=units'))
            ->withStatus(302);
    }

    /**
     * อ่านผลจากโมเดลทีละก้อน JSON — ทนกับกรณีที่โมเดลไม่ยอมตอบบรรทัดละอ็อบเจกต์
     *
     * @param iterable<string> $stream
     * @return list<array{title:string,key_content:string,objectives:string,competencies:string,hours:int}>
     */
    private function collect(iterable $stream, int $limit): array
    {
        $units = [];

        foreach (JsonStream::objects($stream) as $obj) {
            if (isset($obj['done'])) {
                break;
            }

            $title = trim((string) ($obj['title'] ?? ''));
            if ($title === '') {
                continue;
            }

            $units[] = [
                'title' => mb_substr($title, 0, 191),
                'key_content' => mb_substr(trim((string) ($obj['key_content'] ?? '')), 0, 2000),
                'objectives' => mb_substr(trim((string) ($obj['objectives'] ?? '')), 0, 2000),
                'competencies' => mb_substr(trim((string) ($obj['competencies'] ?? '')), 0, 2000),
                'hours' => max(0, min(255, (int) ($obj['hours'] ?? 0))),
            ];

            if (count($units) >= $limit) {
                break;
            }
        }

        return $units;
    }

    /** @param array<string,mixed> $course */
    private function back(Response $response, array $course): Response
    {
        return $response
            ->withHeader('Location', Url::to('/courses/' . $course['id'] . '/units/design'))
            ->withStatus(302);
    }
}
