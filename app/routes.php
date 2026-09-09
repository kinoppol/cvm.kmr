<?php

declare(strict_types=1);

use App\Auth\AuthMiddleware;
use App\Auth\RoleMiddleware;
use App\Controllers\Admin\MigrationController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Support\Url;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    // closure ของ Slim ต้องไม่เป็น static เพราะ Slim ผูก closure เข้ากับ container ก่อนเรียก
    $app->get('/', function (Request $request, Response $response): Response {
        return $response->withHeader('Location', Url::to('/dashboard'))->withStatus(302);
    })->setName('home');

    $app->get('/login', [AuthController::class, 'show'])->setName('login');
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout'])->setName('logout');

    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/dashboard', [DashboardController::class, 'index'])->setName('dashboard');

        $group->group('/admin', function (RouteCollectorProxy $admin): void {
            $admin->get('/migrations', [MigrationController::class, 'index'])->setName('admin.migrations');
            $admin->get('/migrations/preview/{name}', [MigrationController::class, 'preview']);
            $admin->post('/migrations/run', [MigrationController::class, 'run']);
            $admin->post('/migrations/rollback', [MigrationController::class, 'rollback']);
            $admin->post('/migrations/reset', [MigrationController::class, 'reset']);
        })->add(new RoleMiddleware(['admin']));
    })->add(AuthMiddleware::class);
};
