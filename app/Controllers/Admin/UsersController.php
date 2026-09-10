<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Db;
use App\Support\Thai;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * รายชื่อผู้ใช้งานระบบสำหรับผู้ดูแล (ดูอย่างเดียวในรุ่นนี้)
 */
final class UsersController
{
    public function __construct(
        private readonly View $view,
        private readonly Db $db,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $role = (string) ($request->getQueryParams()['role'] ?? '');
        $roles = ['admin', 'teacher', 'student'];
        $where = in_array($role, $roles, true) ? 'WHERE role = ?' : '';
        $params = $where ? [$role] : [];

        $users = $this->db->all(
            "SELECT id, username, full_name, email, role, status, last_login_at
             FROM {users} $where
             ORDER BY FIELD(role, 'admin', 'teacher', 'student'), username
             LIMIT 500",
            $params
        );
        foreach ($users as $i => $u) {
            $users[$i]['last_login_text'] = $u['last_login_at'] ? Thai::ago($u['last_login_at']) : 'ยังไม่เคยเข้าใช้';
        }

        $counts = [];
        foreach ($this->db->all("SELECT role, COUNT(*) AS n FROM {users} GROUP BY role") as $row) {
            $counts[$row['role']] = (int) $row['n'];
        }

        return $this->view->render($response, 'admin/users', [
            'page' => 'admin-users',
            'users' => $users,
            'counts' => $counts,
            'filter' => $role,
        ]);
    }
}
