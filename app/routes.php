<?php

declare(strict_types=1);

use App\Auth\AuthMiddleware;
use App\Auth\RoleMiddleware;
use App\Controllers\Admin\AiController as AdminAiController;
use App\Controllers\Admin\DemoController;
use App\Controllers\Admin\MigrationController;
use App\Controllers\Admin\UsersController as AdminUsersController;
use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\DashboardController;
use App\Controllers\ImpersonationController;
use App\Controllers\LessonController;
use App\Controllers\LessonPlanController;
use App\Controllers\QuizWizardController;
use App\Controllers\ReviewController;
use App\Controllers\SettingsController;
use App\Controllers\StudentController;
use App\Support\Url;
use App\Support\ViewContext;
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

        // เลิกสวมสิทธิ์ — ต้องเข้าถึงได้ทุกบทบาทที่ล็อกอินอยู่ จึงอยู่นอกกลุ่มตามบทบาท
        $group->post('/impersonate/stop', [ImpersonationController::class, 'stop'])->setName('impersonate.stop');

        $group->group('/courses', function (RouteCollectorProxy $t): void {
            $t->get('', [CourseController::class, 'index'])->setName('courses');
            $t->get('/{id:[0-9]+}', [CourseController::class, 'show'])->setName('course.show');
            $t->get('/{courseId:[0-9]+}/lessons/new', [LessonController::class, 'edit']);
            $t->get('/{courseId:[0-9]+}/lessons/{id:[0-9]+}/edit', [LessonController::class, 'edit']);
            $t->post('/{courseId:[0-9]+}/lessons[/{id:[0-9]+}]', [LessonController::class, 'save']);

            $t->get('/{courseId:[0-9]+}/quizzes/create', [QuizWizardController::class, 'create']);
            $t->get('/{courseId:[0-9]+}/quizzes/workspace', [QuizWizardController::class, 'workspace']);
            $t->get('/{courseId:[0-9]+}/quizzes/stream', [QuizWizardController::class, 'stream']);
            $t->get('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}/review', [QuizWizardController::class, 'review']);
            $t->post('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}', [QuizWizardController::class, 'save']);
            $t->post('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}/questions/{qid:[0-9]+}/regenerate', [QuizWizardController::class, 'regenerateOne']);
            $t->post('/{courseId:[0-9]+}/quizzes/{quizId:[0-9]+}/questions/{qid:[0-9]+}/delete', [QuizWizardController::class, 'deleteQuestion']);

            $t->get('/{courseId:[0-9]+}/lesson-plan', [LessonPlanController::class, 'form']);
            $t->get('/{courseId:[0-9]+}/lesson-plan/stream', [LessonPlanController::class, 'stream']);
            $t->get('/{courseId:[0-9]+}/lesson-plan/{id:[0-9]+}', [LessonPlanController::class, 'edit']);
            $t->get('/{courseId:[0-9]+}/lesson-plan/{id:[0-9]+}/export.{format:doc|pdf}', [LessonPlanController::class, 'export']);
            $t->post('/{courseId:[0-9]+}/lesson-plan/{id:[0-9]+}', [LessonPlanController::class, 'save']);
        })->add(new RoleMiddleware(['teacher']));

        $group->get('/review', [ReviewController::class, 'index'])
            ->setName('review')->add(new RoleMiddleware(['teacher']));

        $group->group('/learn', function (RouteCollectorProxy $s): void {
            $s->get('', [StudentController::class, 'courses'])->setName('learn');
            $s->get('/{courseId:[0-9]+}', [StudentController::class, 'course']);
            $s->get('/lessons/{id:[0-9]+}', [StudentController::class, 'lesson']);
            $s->post('/quizzes/{quizId:[0-9]+}/start', [StudentController::class, 'startQuiz']);
            $s->get('/attempts/{attemptId:[0-9]+}', [StudentController::class, 'take']);
            $s->post('/attempts/{attemptId:[0-9]+}/answer', [StudentController::class, 'answer']);
            $s->post('/attempts/{attemptId:[0-9]+}/submit', [StudentController::class, 'submit']);
            $s->get('/attempts/{attemptId:[0-9]+}/result', [StudentController::class, 'result']);
        })->add(new RoleMiddleware(['student']));

        $group->group('/settings', function (RouteCollectorProxy $s): void {
            $s->get('/ai', [SettingsController::class, 'ai'])->setName('settings.ai');
            $s->post('/ai/test', [SettingsController::class, 'test']);
            $s->post('/ai/connect', [SettingsController::class, 'connect']);
            $s->post('/ai/disconnect', [SettingsController::class, 'disconnect']);
            $s->post('/ai/mode', [SettingsController::class, 'mode']);
        })->add(new RoleMiddleware(['teacher']));

        $group->group('/admin', function (RouteCollectorProxy $admin): void {
            $admin->get('/migrations', [MigrationController::class, 'index'])->setName('admin.migrations');
            $admin->get('/migrations/preview/{name}', [MigrationController::class, 'preview']);
            $admin->post('/migrations/run', [MigrationController::class, 'run']);
            $admin->post('/migrations/rollback', [MigrationController::class, 'rollback']);
            $admin->post('/migrations/reset', [MigrationController::class, 'reset']);

            $admin->get('/demo', [DemoController::class, 'index'])->setName('admin.demo');
            $admin->post('/demo/seed', [DemoController::class, 'seed']);

            $admin->get('/ai', [AdminAiController::class, 'index'])->setName('admin.ai');
            $admin->post('/ai/cap', [AdminAiController::class, 'saveCap']);
            $admin->get('/users', [AdminUsersController::class, 'index'])->setName('admin.users');
            $admin->post('/users/{id:[0-9]+}/impersonate', [ImpersonationController::class, 'start']);
        })->add(new RoleMiddleware(['admin']));
    })->add(ViewContext::class)->add(AuthMiddleware::class);
};
