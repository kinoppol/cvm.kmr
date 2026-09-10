<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\Auth;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

final class AuthController
{
    public function __construct(
        private readonly Auth $auth,
        private readonly View $view,
    ) {
    }

    public function show(Request $request, Response $response): Response
    {
        if ($this->auth->check()) {
            return $this->redirect($response, '/dashboard');
        }

        return $this->view->render($response, 'auth/login', ['username' => '']);
    }

    public function login(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, '/login');
        }

        if ($username === '' || $password === '') {
            Flash::error('กรุณากรอกชื่อผู้ใช้และรหัสผ่าน');

            return $this->view->render($response, 'auth/login', ['username' => $username]);
        }

        $ip = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '0.0.0.0');
        $result = $this->auth->attempt($username, $password, $ip);

        if (!$result['ok']) {
            Flash::error($result['message']);

            return $this->view->render($response, 'auth/login', ['username' => $username]);
        }

        $this->auth->log('login');

        return $this->redirect($response, '/dashboard');
    }

    public function logout(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->redirect($response, '/dashboard');
        }

        // ถ้ากำลังสวมสิทธิ์ผู้ใช้อื่นอยู่ การ "ออกจากระบบ" คือกลับไปเป็นผู้ดูแลเดิม
        if ($this->auth->isImpersonating()) {
            $leftUserId = (int) ($_SESSION['user_id'] ?? 0);
            $restored = $this->auth->stopImpersonating();

            if ($restored) {
                $this->auth->log('impersonate.stop', 'user#' . $leftUserId);
                Flash::success('กลับมาเป็นผู้ดูแลระบบแล้ว');

                return $this->redirect($response, '/admin/users');
            }

            Flash::error('บัญชีผู้ดูแลเดิมใช้งานไม่ได้ กรุณาเข้าสู่ระบบใหม่');

            return $this->redirect($response, '/login');
        }

        $this->auth->log('logout');
        $this->auth->logout();

        return $this->redirect($response, '/login');
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }
}
