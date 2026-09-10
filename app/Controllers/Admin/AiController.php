<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * แดชบอร์ดผู้ดูแล: สถานะเครื่อง AI ของวิทยาลัย คิวงาน การใช้งานรายวัน/รายครู และเพดานโควตา
 */
final class AiController
{
    private const CAPS = [40, 60, 80, 0]; // 0 = ไม่จำกัด

    public function __construct(
        private readonly View $view,
        private readonly AiRepository $ai,
        private readonly SettingsRepository $settings,
        private readonly Db $db,
        private readonly Auth $auth,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $endpoint = $this->ai->defaultEndpoint();
        $period = sprintf('%04d-%02d', $this->settings->int('academic_year'), (int) date('n'));
        $cap = $this->settings->int('ai_monthly_quota', 60);
        $teacherCount = $this->db->int("SELECT COUNT(*) FROM {users} WHERE role = 'teacher' AND status = 'active'");

        return $this->view->render($response, 'admin/ai', [
            'page' => 'admin-ai',
            'endpoint' => $endpoint,
            'gpu' => $this->gpuStats($endpoint),
            'models' => $this->loadedModels($endpoint),
            'queue' => $this->queueJobs(),
            'queueLength' => $this->ai->queueLength(),
            'chart' => $this->weeklyChart(),
            'weekTotal' => $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE created_at > (NOW() - INTERVAL 7 DAY)"),
            'usage' => $this->teacherUsage($period),
            'caps' => self::CAPS,
            'cap' => $cap,
            'capTotal' => $cap > 0 ? $cap * $teacherCount : 0,
            'teacherCount' => $teacherCount,
        ]);
    }

    public function saveCap(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $cap = (int) ($data['cap'] ?? 60);
        if (!in_array($cap, self::CAPS, true)) {
            $cap = 60;
        }

        $this->settings->set('ai_monthly_quota', (string) $cap, 'integer', 'ai');
        $this->auth->log('ai.cap.save', null, ['cap' => $cap]);
        Flash::success('บันทึกเพดานโควตาแล้ว · มีผลกับครูทุกคนในเดือนถัดไป');

        return $this->redirect($response);
    }

    /** @return list<array{label:string,value:string,pct:int,tone:string}> */
    private function gpuStats(?array $endpoint): array
    {
        $online = $endpoint && $endpoint['status'] === 'online';
        $today = $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE created_at > CURDATE()");
        // ค่าจำลองระหว่างที่ยังไม่มีเครื่องจริง — ผูกกับเวลาให้ดูมีชีวิต
        $load = $online ? 30 + (int) (abs(sin((float) date('U') / 900)) * 45) : 0;

        return [
            ['label' => 'หน่วยความจำของการ์ดที่ใช้อยู่', 'value' => $online ? '17.2 / 24 GB' : '— / 24 GB', 'pct' => $online ? 72 : 0, 'tone' => 'brand'],
            ['label' => 'อุณหภูมิ', 'value' => $online ? '68 °C' : '—', 'pct' => $online ? 55 : 0, 'tone' => 'ok'],
            ['label' => 'การใช้งานตัวประมวลผล', 'value' => $online ? $load . ' %' : '—', 'pct' => $load, 'tone' => 'brand'],
            ['label' => 'คำขอวันนี้', 'value' => $today . ' ครั้ง', 'pct' => min(100, $today * 3), 'tone' => 'brand'],
        ];
    }

    /** @return list<array{name:string,role:string,vram:string}> */
    private function loadedModels(?array $endpoint): array
    {
        if (!$endpoint || $endpoint['status'] !== 'online') {
            return [];
        }

        return [
            ['name' => (string) ($endpoint['model'] ?: 'typhoon2-8b-instruct'), 'role' => 'โหมดประหยัด', 'vram' => '5.8 GB'],
            ['name' => 'qwen2.5:14b-instruct', 'role' => 'โหมดคุณภาพสูง', 'vram' => '11.4 GB'],
        ];
    }

    /** @return list<array{who:string,what:string,age:string}> */
    private function queueJobs(): array
    {
        $rows = $this->db->all(
            "SELECT j.kind, j.queued_at, u.full_name
             FROM {ai_jobs} j LEFT JOIN {users} u ON u.id = j.user_id
             WHERE j.status IN ('queued','running')
             ORDER BY j.queued_at LIMIT 8"
        );

        return array_map(static function (array $r): array {
            $age = max(0, time() - strtotime((string) $r['queued_at']));

            return [
                'who' => $r['full_name'] ?? 'ไม่ระบุ',
                'what' => match ($r['kind']) {
                    'quiz' => 'ออกข้อสอบ',
                    'lesson_plan' => 'ทำแผนการสอน',
                    default => 'สรุปเนื้อหา',
                },
                'age' => $age < 60 ? $age . ' วิ.' : intdiv($age, 60) . ' นาที',
            ];
        }, $rows);
    }

    /** @return list<array{day:string,n:int,collegeH:string,ownH:string}> */
    private function weeklyChart(): array
    {
        $days = ['อา', 'จ', 'อ', 'พ', 'พฤ', 'ศ', 'ส'];
        $out = [];
        $max = 1;
        $raw = [];

        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i day"));
            $college = $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE source = 'college' AND DATE(created_at) = ?", [$date]);
            $own = $this->db->int("SELECT COUNT(*) FROM {ai_usage_logs} WHERE source = 'byok' AND DATE(created_at) = ?", [$date]);
            $raw[] = ['day' => $days[(int) date('w', strtotime($date))], 'college' => $college, 'own' => $own];
            $max = max($max, $college + $own);
        }

        foreach ($raw as $r) {
            $out[] = [
                'day' => $r['day'],
                'n' => $r['college'] + $r['own'],
                'collegeH' => round($r['college'] / $max * 82) . 'px',
                'ownH' => round($r['own'] / $max * 82) . 'px',
            ];
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function teacherUsage(string $period): array
    {
        $rows = $this->db->all(
            "SELECT u.id, u.full_name,
                    (SELECT COUNT(*) FROM {ai_usage_logs} l WHERE l.user_id = u.id AND l.source = 'college'
                        AND DATE_FORMAT(l.created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')) AS college,
                    (SELECT COUNT(*) FROM {ai_usage_logs} l WHERE l.user_id = u.id AND l.source = 'byok'
                        AND DATE_FORMAT(l.created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')) AS own,
                    q.used_count, q.monthly_limit
             FROM {users} u
             LEFT JOIN {ai_quotas} q ON q.user_id = u.id AND q.period = ?
             WHERE u.role = 'teacher' AND u.status = 'active'
             ORDER BY q.used_count DESC, u.full_name",
            [$period]
        );

        $limit = $this->settings->int('ai_monthly_quota', 60);

        return array_map(static function (array $r) use ($limit): array {
            $used = (int) ($r['used_count'] ?? 0);
            $lim = (int) ($r['monthly_limit'] ?? $limit);
            $left = max(0, $lim - $used);

            return [
                'name' => $r['full_name'],
                'college' => (int) $r['college'] ?: $used,
                'own' => (int) $r['own'],
                'left' => $left === 0 ? 'หมดแล้ว' : $left . ' ครั้ง',
                'left_kind' => $left === 0 ? 'err' : ($left <= 5 ? 'warn' : 'ink2'),
            ];
        }, $rows);
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/ai'))->withStatus(302);
    }
}
