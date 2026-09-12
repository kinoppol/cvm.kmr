<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Domain\CourseRepository;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * รายวิชาทั้งระบบสำหรับผู้ดูแล — ดูว่าครูคนไหนเปิดวิชาอะไรไว้บ้าง ค้นและกรองตามครู/สถานะได้
 */
final class CoursesController
{
    private const STATUSES = ['active', 'archived'];

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $query = trim((string) ($params['q'] ?? ''));
        $teacherId = ((int) ($params['teacher'] ?? 0)) ?: null;
        $status = in_array($params['status'] ?? '', self::STATUSES, true) ? (string) $params['status'] : '';

        $courses = $this->courses->allForAdmin($query, $teacherId, $status);

        return $this->view->render($response, 'admin/courses', [
            'page' => 'admin-courses',
            'courses' => $courses,
            'teachers' => $this->courses->teachersWithCourses(),
            'query' => $query,
            'teacherId' => $teacherId,
            'status' => $status,
            'activeCount' => count(array_filter($courses, static fn (array $c): bool => $c['status'] === 'active')),
        ]);
    }
}
