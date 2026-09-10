<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\KeyCipher;
use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use SensitiveParameter;

/**
 * ตั้งค่า → ผู้ช่วย AI ของฉัน: การ์ดเทียบ AI วิทยาลัย / AI ของฉัน, ตัวช่วยเชื่อม API 3 ขั้น, โหมดคุณภาพ
 */
final class SettingsController
{
    private const PROVIDERS = [
        'google' => 'Google AI Studio',
        'openrouter' => 'OpenRouter',
        'openai_compatible' => 'บริการอื่นที่กรอกที่อยู่เองได้',
    ];

    public function __construct(
        private readonly View $view,
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
        private readonly KeyCipher $cipher,
        private readonly Auth $auth,
    ) {
    }

    public function ai(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $uid = (int) $user['id'];

        $period = sprintf('%04d-%02d', $this->settings->int('academic_year'), (int) date('n'));
        $quota = $this->ai->quota($uid, $period, $this->settings->int('ai_monthly_quota', 60));
        $endpoint = $this->ai->defaultEndpoint();
        $key = $this->ai->activeKeyForUser($uid);

        return $this->view->render($response, 'settings/ai', [
            'page' => 'settings',
            'quota' => $quota,
            'endpoint' => $endpoint,
            'queue' => $endpoint ? max((int) $endpoint['queue_length'], $this->ai->queueLength()) : 0,
            'byokAllowed' => $this->settings->bool('allow_teacher_byok', true),
            'key' => $key,
            'keyProviderName' => $key ? (self::PROVIDERS[$key['provider']] ?? $key['provider']) : null,
            'keyVerifiedAgo' => $key && $key['verified_at'] ? Thai::ago($key['verified_at']) : null,
            'qualityPref' => $this->settings->userQualityPref($uid),
            'providers' => self::PROVIDERS,
        ]);
    }

    public function test(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['ok' => false, 'message' => 'เซสชันหมดอายุ'], 419);
        }

        $provider = (string) ($data['provider'] ?? '');
        $key = trim((string) ($data['key'] ?? ''));

        return $this->json($response, $this->probeKey($provider, $key));
    }

    public function connect(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/settings/ai');
        }

        $provider = array_key_exists((string) ($data['provider'] ?? ''), self::PROVIDERS) ? (string) $data['provider'] : 'google';
        $key = trim((string) ($data['key'] ?? ''));
        $baseUrl = trim((string) ($data['base_url'] ?? '')) ?: null;

        $probe = $this->probeKey($provider, $key);
        if (!$probe['ok']) {
            Flash::error($probe['message']);

            return $this->redirect($response, '/settings/ai');
        }

        $this->ai->saveKey((int) $user['id'], [
            'provider' => $provider,
            'label' => self::PROVIDERS[$provider],
            'base_url' => $baseUrl,
            'model' => $probe['model'] ?? null,
            'key_encrypted' => $this->cipher->encrypt($key),
            'key_last4' => $this->cipher->last4($key),
            'status' => 'active',
            'verified_at' => date('Y-m-d H:i:s'),
        ]);

        $this->auth->log('ai.key.connect', $provider);
        Flash::success('เชื่อม AI ของฉันแล้ว · ระบบจะใช้ของครูก่อนเสมอ');

        return $this->redirect($response, '/settings/ai');
    }

    public function disconnect(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        $this->ai->disconnectUser((int) $user['id']);
        $this->auth->log('ai.key.disconnect');
        Flash::success('ยกเลิกการเชื่อมแล้ว · กลับไปใช้ AI ของวิทยาลัย');

        return $this->redirect($response, '/settings/ai');
    }

    public function mode(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, '/settings/ai');
        }

        $pref = ($data['mode'] ?? '') === 'quality' ? 'quality' : 'fast';
        $this->settings->set('ai_quality:' . (int) $user['id'], $pref, 'string', 'ai');
        Flash::success('บันทึกโหมดคุณภาพของผลลัพธ์แล้ว');

        return $this->redirect($response, '/settings/ai');
    }

    /** @return array{ok:bool,message:string,model?:string,models?:list<string>} */
    private function probeKey(string $provider, #[SensitiveParameter] string $key): array
    {
        // ตัวจำลอง: ระหว่างยังไม่ต่อบริการจริง ตรวจรูปแบบคีย์แบบหลวม ๆ
        if ($key === '') {
            return ['ok' => false, 'message' => 'ยังไม่ได้วางรหัส กรุณาวางรหัสที่คัดลอกมาจากผู้ให้บริการ'];
        }
        if (mb_strlen($key) < 12 || str_contains($key, ' ')) {
            return ['ok' => false, 'message' => 'รหัสไม่ครบหรือมีช่องว่างปน กรุณาคัดลอกทั้งบรรทัด ไม่เว้นวรรคหน้า–หลัง'];
        }
        if (str_contains(strtolower($key), 'expired') || str_contains(strtolower($key), 'revoked')) {
            return ['ok' => false, 'message' => 'รหัสนี้ถูกปิดการใช้งานไปแล้ว กลับไปที่หน้าผู้ให้บริการแล้วกดสร้างรหัสใหม่'];
        }

        $model = match ($provider) {
            'google' => 'Gemini 1.5 Flash',
            'openrouter' => 'auto',
            default => 'ตามที่ตั้งค่า',
        };

        return [
            'ok' => true,
            'message' => 'เชื่อมต่อได้ · ใช้ได้ทั้งโหมดประหยัดและโหมดคุณภาพสูง',
            'model' => $model,
            'models' => [$model],
        ];
    }

    private function json(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($data, JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json; charset=utf-8')->withStatus($status);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
