<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\CourseRepository;
use App\Domain\EnrollmentRepository;
use App\Domain\LessonRepository;
use App\Domain\QuizRepository;
use App\Support\Csrf;
use App\Support\Db;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

final class CourseController
{
    private const TABS = ['lessons', 'quizzes', 'students', 'scores'];

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly QuizRepository $quizzes,
        private readonly EnrollmentRepository $enrollments,
        private readonly Db $db,
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $courses = $this->courses->forTeacher((int) $user['id']);

        return $this->view->render($response, 'courses/index', [
            'page' => 'courses',
            'courses' => $courses,
            'landingVisible' => $this->courses->landingEnabledForTeacher((int) $user['id']),
        ]);
    }

    /** ครูเปิด/ปิดการแสดงรายวิชาของตนเองในหน้าแรกสาธารณะ */
    public function landingVisibility(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $response->withHeader('Location', Url::to('/courses'))->withStatus(302);
        }

        $visible = ($data['visible'] ?? '') === '1';
        $this->courses->setLandingForTeacher((int) $user['id'], $visible);

        Flash::success($visible
            ? 'เปิดการแสดงรายวิชาของคุณในหน้าแรกสาธารณะแล้ว'
            : 'ปิดการแสดงรายวิชาของคุณในหน้าแรกสาธารณะแล้ว');

        return $response->withHeader('Location', Url::to('/courses'))->withStatus(302);
    }

    /** ฟอร์มเพิ่มรายวิชาใหม่ หรือแก้ไขรายวิชาเดิมของครูคนนี้ */
    public function edit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = isset($args['id'])
            ? $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id'])
            : null;

        return $this->view->render($response, 'courses/edit', [
            'page' => 'courses',
            'course' => $course,
            'terms' => $this->courses->terms(),
            'classrooms' => $this->courses->classrooms(),
            'currentTermId' => $this->courses->currentTermId(),
        ]);
    }

    /** บันทึกรายวิชา — ใช้ทั้งตอนเพิ่มใหม่และตอนแก้ไข */
    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->back($response);
        }

        $course = isset($args['id'])
            ? $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id'])
            : null;

        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        $termId = ((int) ($data['term_id'] ?? 0)) ?: null;
        $classroomId = ((int) ($data['classroom_id'] ?? 0)) ?: null;

        if ($code === '' || $name === '') {
            Flash::error('กรุณากรอกรหัสวิชาและชื่อวิชาให้ครบ');

            return $this->back($response, $course, true);
        }

        if ($this->courses->codeTaken($code, $termId, $classroomId, $course['id'] ?? null)) {
            Flash::error('มีรายวิชารหัส ' . $code . ' ในภาคเรียนและกลุ่มเรียนนี้อยู่แล้ว');

            return $this->back($response, $course, true);
        }

        $fields = [
            'code' => $code,
            'name' => $name,
            'credits' => max(0, min(9.9, (float) ($data['credits'] ?? 3))),
            'theory_hours' => max(0, min(255, (int) ($data['theory_hours'] ?? 0))),
            'practice_hours' => max(0, min(255, (int) ($data['practice_hours'] ?? 0))),
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'term_id' => $termId,
            'classroom_id' => $classroomId,
        ];

        if ($course === null) {
            $id = $this->courses->create((int) $user['id'], $fields);
            Flash::success('เพิ่มรายวิชา ' . $name . ' แล้ว');
        } else {
            $id = (int) $course['id'];
            $this->courses->update($id, $fields);
            Flash::success('บันทึกการแก้ไขรายวิชาแล้ว');
        }

        return $response->withHeader('Location', Url::to('/courses/' . $id))->withStatus(302);
    }

    /** เก็บรายวิชาเข้าคลัง — ไม่ลบ เพราะบทเรียนและคะแนนของนักเรียนยังผูกอยู่ */
    public function archive(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $data = (array) $request->getParsedBody();

        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->back($response);
        }

        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);
        $this->courses->archive((int) $course['id']);
        Flash::success('เก็บรายวิชา ' . $course['name'] . ' เข้าคลังแล้ว · ข้อมูลบทเรียนและคะแนนยังอยู่ครบ');

        return $this->back($response);
    }

    /**
     * กลับไปหน้าฟอร์มเดิม เพื่อให้ครูแก้ข้อมูลต่อได้ทันทีเมื่อบันทึกไม่ผ่าน
     *
     * @param array<string,mixed>|null $course
     */
    private function back(Response $response, ?array $course = null, bool $creating = false): Response
    {
        $path = match (true) {
            $course !== null => '/courses/' . $course['id'] . '/edit',
            $creating => '/courses/new',
            default => '/courses',
        };

        return $response->withHeader('Location', Url::to($path))->withStatus(302);
    }

    public function show(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->requireOwnedCourse($request, (int) $args['id'], (int) $user['id']);

        $tab = (string) ($request->getQueryParams()['tab'] ?? 'lessons');
        if (!in_array($tab, self::TABS, true)) {
            $tab = 'lessons';
        }

        $data = [
            'page' => 'courses',
            'course' => $course,
            'tab' => $tab,
        ];

        $data += match ($tab) {
            'lessons' => ['lessons' => $this->decorateLessons($this->lessons->forCourse($course['id']))],
            'quizzes' => ['quizzes' => $this->decorateQuizzes($this->quizzes->forCourse($course['id']))],
            'students' => ['students' => $this->enrollments->studentsInCourse($course['id'])],
            'scores' => $this->scoreBoard($course['id']),
            default => [],
        };

        return $this->view->render($response, 'courses/show', $data);
    }

    /** @return array<string,mixed> */
    private function requireOwnedCourse(Request $request, int $courseId, int $teacherId): array
    {
        $course = $this->courses->find($courseId);
        if ($course === null) {
            throw new HttpNotFoundException($request, 'ไม่พบรายวิชานี้');
        }
        if ((int) $course['teacher_id'] !== $teacherId) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้เป็นผู้สอนรายวิชานี้');
        }

        return $course;
    }

    /** @param list<array<string,mixed>> $lessons @return list<array<string,mixed>> */
    private function decorateLessons(array $lessons): array
    {
        foreach ($lessons as $i => $l) {
            [$tag, $kind] = match ($l['review_status']) {
                'published' => ['เผยแพร่แล้ว', 'ok'],
                'pending' => ['รอครูตรวจ', 'warn'],
                default => ['ฉบับร่าง', 'muted'],
            };
            $lessons[$i]['no'] = str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT);
            $lessons[$i]['tag'] = $tag;
            $lessons[$i]['tag_kind'] = $kind;
            $lessons[$i]['meta'] = $l['source'] === 'ai' ? 'ร่างโดยผู้ช่วย AI' : ($l['attachment_count'] > 0
                ? sprintf('ใบความรู้ %d ไฟล์', $l['attachment_count'])
                : 'ยังไม่มีไฟล์ประกอบ');
        }

        return $lessons;
    }

    /** @param list<array<string,mixed>> $quizzes @return list<array<string,mixed>> */
    private function decorateQuizzes(array $quizzes): array
    {
        foreach ($quizzes as $i => $q) {
            [$tag, $kind] = match ($q['review_status']) {
                'published' => ['เผยแพร่แล้ว', 'ok'],
                'pending' => ['รอครูตรวจ', 'warn'],
                default => ['ฉบับร่าง', 'muted'],
            };
            $quizzes[$i]['tag'] = $tag;
            $quizzes[$i]['tag_kind'] = $kind;
            $quizzes[$i]['time'] = Thai::ago($q['created_at']);
            $quizzes[$i]['meta'] = sprintf(
                '%d ข้อ%s · ส่งแล้ว %d คน',
                $q['question_count'],
                $q['time_limit_minutes'] ? ' · เวลา ' . $q['time_limit_minutes'] . ' นาที' : '',
                $q['submitted_count']
            );
        }

        return $quizzes;
    }

    /** @return array{scoreQuizzes:list<array<string,mixed>>,scoreRows:list<array<string,mixed>>} */
    private function scoreBoard(int $courseId): array
    {
        $quizzes = $this->db->all(
            'SELECT id, title FROM {quizzes} WHERE course_id = ? AND review_status = \'published\' ORDER BY created_at',
            [$courseId]
        );
        $students = $this->enrollments->studentsInCourse($courseId);

        $rows = [];
        foreach ($students as $s) {
            $cells = [];
            $total = 0.0;
            foreach ($quizzes as $q) {
                $score = $this->db->value(
                    'SELECT score FROM {quiz_attempts}
                     WHERE quiz_id = ? AND student_id = ? AND status = \'graded\'
                     ORDER BY score DESC LIMIT 1',
                    [$q['id'], $s['id']]
                );
                $cells[] = $score === null ? '—' : (string) (float) $score;
                $total += (float) $score;
            }
            $rows[] = ['name' => $s['full_name'], 'cells' => $cells, 'total' => $total > 0 ? (string) $total : '—'];
        }

        return ['scoreQuizzes' => $quizzes, 'scoreRows' => $rows];
    }
}
