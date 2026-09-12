<?php

declare(strict_types=1);

use App\Auth\AuthMiddleware;
use App\Auth\RoleMiddleware;
use App\Controllers\Admin\AiController as AdminAiController;
use App\Controllers\Admin\DemoController;
use App\Controllers\Admin\GeneralController;
use App\Controllers\Admin\LogsController;
use App\Controllers\Admin\MigrationController;
use App\Controllers\Admin\UsersController as AdminUsersController;
use App\Controllers\AiChatController;
use App\Controllers\AppearanceController;
use App\Controllers\AuthController;
use App\Controllers\CourseController;
use App\Controllers\DashboardController;
use App\Controllers\ImpersonationController;
use App\Controllers\LandingController;
use App\Controllers\BoardController;
use App\Controllers\UnitController;
use App\Controllers\LessonPlanController;
use App\Controllers\QuizWizardController;
use App\Controllers\RegisterController;
use App\Controllers\ResetPasswordController;
use App\Controllers\ReviewController;
use App\Controllers\SettingsController;
use App\Controllers\StudentController;
use App\Controllers\Teacher\StudentsController as TeacherStudentsController;
use App\Support\ViewContext;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

return static function (App $app): void {
    $app->get('/', [LandingController::class, 'index'])->setName('home');

    $app->get('/login', [AuthController::class, 'show'])->setName('login');
    $app->post('/login', [AuthController::class, 'login']);
    $app->post('/logout', [AuthController::class, 'logout'])->setName('logout');

    // ครูทั่วไปสมัครเข้าใช้ระบบเอง — บัญชีเริ่มที่สถานะ "รออนุมัติ" เสมอ
    $app->get('/register', [RegisterController::class, 'show'])->setName('register');
    $app->post('/register', [RegisterController::class, 'register']);

    // ลิงก์รีเซ็ตรหัสผ่านที่ผู้ดูแลระบบสร้างให้ครูที่ลืมรหัสผ่าน — ไม่ต้องล็อกอินก่อน
    $app->get('/reset-password/{token}', [ResetPasswordController::class, 'show'])->setName('reset-password');
    $app->post('/reset-password/{token}', [ResetPasswordController::class, 'update']);

    $app->group('', function (RouteCollectorProxy $group): void {
        $group->get('/dashboard', [DashboardController::class, 'index'])->setName('dashboard');

        // เลิกสวมสิทธิ์ — ต้องเข้าถึงได้ทุกบทบาทที่ล็อกอินอยู่ จึงอยู่นอกกลุ่มตามบทบาท
        $group->post('/impersonate/stop', [ImpersonationController::class, 'stop'])->setName('impersonate.stop');

        // ช่องสนทนาลอยสำหรับทดสอบว่าผู้ช่วย AI เชื่อมต่อและตอบกลับได้จริง (ไม่ตัดโควตา)
        $group->group('/ai/chat', function (RouteCollectorProxy $c): void {
            $c->get('/status', [AiChatController::class, 'status']);
            $c->get('/stream', [AiChatController::class, 'stream']);
            $c->post('/prepare', [AiChatController::class, 'prepare']);
            $c->post('/attach', [AiChatController::class, 'attach']);
        })->add(new RoleMiddleware(['teacher', 'admin']));

        // ผู้ช่วยสร้างรายวิชาให้ตามคำขอ — ครูต้องกดยืนยันจากการ์ดในช่องสนทนาก่อนเสมอ
        $group->post('/ai/chat/create-course', [AiChatController::class, 'createCourse'])
            ->add(new RoleMiddleware(['teacher']));

        $group->group('/courses', function (RouteCollectorProxy $t): void {
            $t->get('', [CourseController::class, 'index'])->setName('courses');
            $t->post('/landing-visibility', [CourseController::class, 'landingVisibility']);
            $t->get('/new', [CourseController::class, 'edit'])->setName('course.new');
            $t->post('', [CourseController::class, 'save']);
            $t->get('/{id:[0-9]+}', [CourseController::class, 'show'])->setName('course.show');
            $t->get('/{id:[0-9]+}/edit', [CourseController::class, 'edit']);
            $t->post('/{id:[0-9]+}', [CourseController::class, 'save']);
            $t->post('/{id:[0-9]+}/archive', [CourseController::class, 'archive']);
            $t->get('/{courseId:[0-9]+}/units/new', [UnitController::class, 'edit']);
            $t->get('/{courseId:[0-9]+}/units/{id:[0-9]+}/edit', [UnitController::class, 'edit']);
            $t->post('/{courseId:[0-9]+}/units[/{id:[0-9]+}]', [UnitController::class, 'save']);
            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/sections', [UnitController::class, 'addSection']);
            $t->post('/{courseId:[0-9]+}/units/{id:[0-9]+}/sections/{sectionId:[0-9]+}/delete', [UnitController::class, 'deleteSection']);

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

        // ครูจัดการข้อมูลนักเรียนของสถานศึกษาตัวเอง — เพิ่มทีละคนหรือนำเข้าจาก Excel
        $group->group('/students', function (RouteCollectorProxy $s): void {
            $s->get('', [TeacherStudentsController::class, 'index'])->setName('students');
            $s->get('/new', [TeacherStudentsController::class, 'create']);
            $s->post('', [TeacherStudentsController::class, 'store']);
            $s->get('/import', [TeacherStudentsController::class, 'importForm']);
            $s->get('/template', [TeacherStudentsController::class, 'template']);
            $s->post('/import', [TeacherStudentsController::class, 'import']);
            $s->get('/{id:[0-9]+}/edit', [TeacherStudentsController::class, 'edit']);
            $s->post('/{id:[0-9]+}', [TeacherStudentsController::class, 'update']);
        })->add(new RoleMiddleware(['teacher']));

        $group->group('/board', function (RouteCollectorProxy $b): void {
            $b->get('', [BoardController::class, 'index'])->setName('board');
            $b->get('/new', [BoardController::class, 'newGroup']);
            $b->post('', [BoardController::class, 'saveGroup']);
            $b->get('/{groupId:[0-9]+}', [BoardController::class, 'group']);
            $b->post('/{groupId:[0-9]+}/join', [BoardController::class, 'join']);
            $b->post('/{groupId:[0-9]+}/leave', [BoardController::class, 'leave']);
            $b->get('/{groupId:[0-9]+}/members', [BoardController::class, 'members']);
            $b->post('/{groupId:[0-9]+}/members/{userId:[0-9]+}/approve', [BoardController::class, 'approveMember']);
            $b->post('/{groupId:[0-9]+}/members/{userId:[0-9]+}/reject', [BoardController::class, 'rejectMember']);
            $b->get('/{groupId:[0-9]+}/topics/new', [BoardController::class, 'newTopic']);
            $b->post('/{groupId:[0-9]+}/topics', [BoardController::class, 'saveTopic']);
            $b->get('/{groupId:[0-9]+}/topics/{topicId:[0-9]+}', [BoardController::class, 'topic']);
            $b->post('/{groupId:[0-9]+}/topics/{topicId:[0-9]+}/replies', [BoardController::class, 'saveReply']);
        })->add(new RoleMiddleware(['teacher']));

        $group->group('/learn', function (RouteCollectorProxy $s): void {
            $s->get('', [StudentController::class, 'courses'])->setName('learn');
            $s->get('/{courseId:[0-9]+}', [StudentController::class, 'course']);
            $s->get('/units/{id:[0-9]+}', [StudentController::class, 'unit']);
            $s->post('/quizzes/{quizId:[0-9]+}/start', [StudentController::class, 'startQuiz']);
            $s->get('/attempts/{attemptId:[0-9]+}', [StudentController::class, 'take']);
            $s->post('/attempts/{attemptId:[0-9]+}/answer', [StudentController::class, 'answer']);
            $s->post('/attempts/{attemptId:[0-9]+}/submit', [StudentController::class, 'submit']);
            $s->get('/attempts/{attemptId:[0-9]+}/result', [StudentController::class, 'result']);
        })->add(new RoleMiddleware(['student']));

        // สีหลักของหน้าจอ — ผู้ใช้ทุกบทบาทตั้งของตัวเองได้ ผู้ดูแลตั้งค่าเริ่มต้นของระบบได้ด้วย
        $group->get('/settings/appearance', [AppearanceController::class, 'index'])->setName('appearance');
        $group->post('/settings/appearance', [AppearanceController::class, 'save']);

        $group->group('/settings', function (RouteCollectorProxy $s): void {
            $s->get('/ai', [SettingsController::class, 'ai'])->setName('settings.ai');
            $s->post('/ai/test', [SettingsController::class, 'test']);
            $s->post('/ai/connect', [SettingsController::class, 'connect']);
            $s->post('/ai/disconnect', [SettingsController::class, 'disconnect']);
            $s->post('/ai/mode', [SettingsController::class, 'mode']);
            $s->post('/ai/route', [SettingsController::class, 'route']);
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
            $admin->post('/ai/endpoint', [AdminAiController::class, 'saveEndpoint']);
            $admin->post('/ai/endpoint/test', [AdminAiController::class, 'testEndpoint']);
            $admin->post('/ai/courses/{id:[0-9]+}/features', [AdminAiController::class, 'saveCourseFeatures']);
            $admin->get('/users', [AdminUsersController::class, 'index'])->setName('admin.users');
            $admin->post('/users/{id:[0-9]+}/impersonate', [ImpersonationController::class, 'start']);
            $admin->post('/users/{id:[0-9]+}/approve', [AdminUsersController::class, 'approve']);
            $admin->post('/users/{id:[0-9]+}/reject', [AdminUsersController::class, 'reject']);
            $admin->post('/users/settings', [AdminUsersController::class, 'saveSettings']);
            $admin->post('/users/{id:[0-9]+}/reset-password', [AdminUsersController::class, 'resetPassword']);
            $admin->post('/users/{id:[0-9]+}/reset-link', [AdminUsersController::class, 'resetLink']);

            $admin->get('/logs', [LogsController::class, 'index'])->setName('admin.logs');
            $admin->get('/logs/download', [LogsController::class, 'download']);

            $admin->get('/settings', [GeneralController::class, 'index'])->setName('admin.settings');
            $admin->post('/settings', [GeneralController::class, 'save']);
        })->add(new RoleMiddleware(['admin']));
    })->add(ViewContext::class)->add(AuthMiddleware::class);
};
