<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Auth\Auth;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * รายชื่อผู้ใช้งานระบบสำหรับผู้ดูแล — ดูตามบทบาท สวมสิทธิ์ชั่วคราว และอนุมัติ/ปฏิเสธ
 * ครูที่สมัครสมาชิกเอง (สถานะ "pending" จาก `RegisterController`)
 */
final class UsersController
{
    public function __construct(
        private readonly View $view,
        private readonly Db $db,
        private readonly Auth $auth,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $role = (string) ($request->getQueryParams()['role'] ?? '');
        $counts = [];
        foreach ($this->db->all("SELECT role, COUNT(*) AS n FROM {users} GROUP BY role") as $row) {
            $counts[$row['role']] = (int) $row['n'];
        }
        $pendingCount = $this->db->int("SELECT COUNT(*) FROM {users} WHERE status = 'pending'");

        if ($role === 'pending') {
            $pendingUsers = $this->db->all(
                "SELECT id, username, full_name, email, subject_area, institution, created_at
                 FROM {users} WHERE status = 'pending' ORDER BY created_at"
            );
            foreach ($pendingUsers as $i => $u) {
                $pendingUsers[$i]['created_text'] = Thai::ago($u['created_at']);
            }

            return $this->view->render($response, 'admin/users', [
                'page' => 'admin-users',
                'users' => [],
                'pendingUsers' => $pendingUsers,
                'counts' => $counts,
                'filter' => 'pending',
                'pendingCount' => $pendingCount,
                'requireApproval' => $this->settings->bool('registration_require_approval', true),
            ]);
        }

        $roles = ['admin', 'teacher', 'student'];
        $where = in_array($role, $roles, true) ? 'WHERE role = ?' : '';
        $params = $where ? [$role] : [];

        $users = $this->db->all(
            "SELECT id, username, full_name, email, role, status, last_login_at
             FROM {users} $where
             ORDER BY FIELD(status, 'pending', 'active', 'suspended'), FIELD(role, 'admin', 'teacher', 'student'), username
             LIMIT 500",
            $params
        );
        foreach ($users as $i => $u) {
            $users[$i]['last_login_text'] = $u['last_login_at'] ? Thai::ago($u['last_login_at']) : 'ยังไม่เคยเข้าใช้';
        }

        return $this->view->render($response, 'admin/users', [
            'page' => 'admin-users',
            'users' => $users,
            'pendingUsers' => [],
            'counts' => $counts,
            'filter' => $role,
            'pendingCount' => $pendingCount,
            'requireApproval' => $this->settings->bool('registration_require_approval', true),
        ]);
    }

    /** อนุมัติครูที่สมัครสมาชิกเอง — เปิดให้เข้าสู่ระบบได้ */
    public function approve(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $id = (int) $args['id'];
        $target = $this->db->first('SELECT full_name FROM {users} WHERE id = ?', [$id]);

        if ($target === null || !$this->auth->approveTeacher($id)) {
            Flash::error('อนุมัติไม่สำเร็จ — อาจถูกดำเนินการไปแล้วหรือไม่พบคำขอนี้');

            return $this->redirect($response);
        }

        $this->auth->log('user.approve', 'user#' . $id);
        Flash::success('อนุมัติบัญชีของ ' . $target['full_name'] . ' แล้ว · เข้าสู่ระบบได้ทันที');

        return $this->redirect($response);
    }

    /** ปฏิเสธคำขอสมัคร — ลบบัญชีที่ยังไม่เคยใช้งานจริงทิ้ง */
    public function reject(Request $request, Response $response, array $args): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $id = (int) $args['id'];
        $target = $this->db->first('SELECT full_name FROM {users} WHERE id = ?', [$id]);

        if ($target === null || !$this->auth->rejectTeacher($id)) {
            Flash::error('ปฏิเสธไม่สำเร็จ — อาจถูกดำเนินการไปแล้วหรือไม่พบคำขอนี้');

            return $this->redirect($response);
        }

        $this->auth->log('user.reject', 'user#' . $id);
        Flash::warning('ปฏิเสธคำขอสมัครของ ' . $target['full_name'] . ' แล้ว');

        return $this->redirect($response);
    }

    /** บันทึกการตั้งค่าการสมัครสมาชิก — ต้องอนุมัติก่อนหรือเข้าใช้ได้เลย */
    public function saveSettings(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response);
        }

        $require = isset($data['require_approval']) && $data['require_approval'] === '1';
        $this->settings->set('registration_require_approval', $require ? '1' : '0', 'boolean', 'registration');
        $this->auth->log('registration.settings', null, ['require_approval' => $require]);

        Flash::success($require
            ? 'เปิดการอนุมัติแล้ว ครูที่สมัครใหม่จะต้องรอผู้ดูแลอนุมัติก่อน'
            : 'ปิดการอนุมัติแล้ว ครูที่สมัครใหม่จะเข้าใช้งานได้ทันที'
        );

        return $response->withHeader('Location', Url::to('/admin/users?role=pending'))->withStatus(302);
    }

    private function redirect(Response $response): Response
    {
        return $response->withHeader('Location', Url::to('/admin/users'))->withStatus(302);
    }
}
