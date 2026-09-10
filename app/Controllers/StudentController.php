<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Domain\AttemptRepository;
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

/**
 * มุมมองนักเรียน: ดูเนื้อหาบทเรียนที่เผยแพร่แล้ว และทำแบบทดสอบ
 */
final class StudentController
{
    public function __construct(
        private readonly View $view,
        private readonly EnrollmentRepository $enrollments,
        private readonly LessonRepository $lessons,
        private readonly QuizRepository $quizzes,
        private readonly AttemptRepository $attempts,
        private readonly Db $db,
    ) {
    }

    public function courses(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');

        return $this->view->render($response, 'learn/courses', [
            'page' => 'learn',
            'firstName' => explode(' ', trim($user['full_name']))[0],
            'courses' => $this->enrollments->coursesForStudent((int) $user['id']),
        ]);
    }

    public function course(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $courseId = (int) $args['courseId'];
        $this->requireEnrolled($request, $courseId, (int) $user['id']);

        $course = $this->db->first('SELECT c.*, u.full_name AS teacher_name FROM {courses} c LEFT JOIN {users} u ON u.id = c.teacher_id WHERE c.id = ?', [$courseId]);

        $lessons = array_values(array_filter(
            $this->lessons->forCourse($courseId),
            static fn (array $l): bool => $l['review_status'] === 'published'
        ));

        $quizzes = [];
        foreach ($this->quizzes->forCourse($courseId) as $q) {
            if ($q['review_status'] !== 'published') {
                continue;
            }
            $best = $this->attempts->bestScore((int) $q['id'], (int) $user['id']);
            $active = $this->attempts->inProgress((int) $q['id'], (int) $user['id']);
            $quizzes[] = $q + [
                'best' => $best,
                'active_attempt' => $active['id'] ?? null,
                'status_label' => $active ? 'กำลังทำอยู่' : ($best !== null ? sprintf('ได้ %s คะแนน', rtrim(rtrim((string) $best, '0'), '.')) : 'ยังไม่ได้ทำ'),
            ];
        }

        return $this->view->render($response, 'learn/course', [
            'page' => 'learn',
            'course' => $course,
            'lessons' => $lessons,
            'quizzes' => $quizzes,
        ]);
    }

    public function lesson(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $lesson = $this->lessons->find((int) $args['id']);
        if ($lesson === null || $lesson['review_status'] !== 'published') {
            throw new HttpNotFoundException($request, 'ไม่พบบทเรียนนี้');
        }
        $this->requireEnrolled($request, (int) $lesson['course_id'], (int) $user['id']);
        $course = $this->db->first('SELECT * FROM {courses} WHERE id = ?', [(int) $lesson['course_id']]);
        $files = $this->db->all('SELECT * FROM {lesson_attachments} WHERE lesson_id = ?', [(int) $lesson['id']]);

        return $this->view->render($response, 'learn/lesson', [
            'page' => 'learn',
            'course' => $course,
            'lesson' => $lesson,
            'files' => $files,
        ]);
    }

    public function startQuiz(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $quiz = $this->quizzes->find((int) $args['quizId']);
        if ($quiz === null || $quiz['review_status'] !== 'published') {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }
        $this->requireEnrolled($request, (int) $quiz['course_id'], (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่');

            return $this->redirect($response, "/learn/{$quiz['course_id']}");
        }

        $active = $this->attempts->inProgress((int) $quiz['id'], (int) $user['id']);
        $attemptId = $active['id'] ?? $this->attempts->start((int) $quiz['id'], (int) $user['id']);

        return $this->redirect($response, "/learn/attempts/$attemptId");
    }

    public function take(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        if ($attempt['status'] !== 'in_progress') {
            return $this->redirect($response, "/learn/attempts/{$attempt['id']}/result");
        }

        $questions = $this->quizzes->questions((int) $quiz['id']);
        $saved = $this->attempts->answers((int) $attempt['id']);

        // ซ่อนเฉลยจากฝั่งนักเรียน
        $view = [];
        foreach ($questions as $i => $q) {
            $view[] = [
                'id' => (int) $q['id'],
                'no' => $i + 1,
                'type' => $q['type'],
                'question' => $q['question'],
                'choices' => array_map(static fn (array $c): array => [
                    'id' => (int) $c['id'], 'label' => $c['label'], 'content' => $c['content'],
                ], $q['choices']),
                'answered_choice' => isset($saved[(int) $q['id']]) ? ($saved[(int) $q['id']]['choice_id'] !== null ? (int) $saved[(int) $q['id']]['choice_id'] : null) : null,
                'answered_text' => $saved[(int) $q['id']]['answer_text'] ?? '',
            ];
        }

        return $this->view->render($response, 'learn/take', [
            'page' => 'learn',
            'bare' => true,
            'course' => $this->db->first('SELECT * FROM {courses} WHERE id = ?', [(int) $quiz['course_id']]),
            'quiz' => $quiz,
            'attempt' => $attempt,
            'questions' => $view,
            'timeLimit' => $quiz['time_limit_minutes'] ? (int) $quiz['time_limit_minutes'] : null,
            'startedAt' => strtotime((string) $attempt['started_at']),
        ]);
    }

    public function answer(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null) || $attempt['status'] !== 'in_progress') {
            return $this->json($response, ['ok' => false], 419);
        }

        $questionId = (int) ($data['question'] ?? 0);
        $valid = $this->db->int('SELECT COUNT(*) FROM {quiz_questions} WHERE id = ? AND quiz_id = ?', [$questionId, (int) $quiz['id']]);
        if ($valid === 0) {
            return $this->json($response, ['ok' => false], 422);
        }

        $choiceId = isset($data['choice']) && $data['choice'] !== '' ? (int) $data['choice'] : null;
        if ($choiceId !== null) {
            $ok = $this->db->int('SELECT COUNT(*) FROM {quiz_choices} WHERE id = ? AND question_id = ?', [$choiceId, $questionId]);
            if ($ok === 0) {
                $choiceId = null;
            }
        }
        $text = isset($data['text']) ? mb_substr(trim((string) $data['text']), 0, 4000) : null;

        $this->attempts->saveAnswer((int) $attempt['id'], $questionId, $choiceId, $text ?: null);

        return $this->json($response, ['ok' => true]);
    }

    public function submit(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ');

            return $this->redirect($response, "/learn/attempts/{$attempt['id']}");
        }

        if ($attempt['status'] === 'in_progress') {
            $this->attempts->grade((int) $attempt['id'], (int) $quiz['id']);
        }

        return $this->redirect($response, "/learn/attempts/{$attempt['id']}/result");
    }

    public function result(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        [$attempt, $quiz] = $this->requireOwnAttempt($request, (int) $args['attemptId'], (int) $user['id']);

        if ($attempt['status'] === 'in_progress') {
            return $this->redirect($response, "/learn/attempts/{$attempt['id']}");
        }

        $duration = $attempt['submitted_at']
            ? strtotime((string) $attempt['submitted_at']) - strtotime((string) $attempt['started_at'])
            : 0;

        return $this->view->render($response, 'learn/result', [
            'page' => 'learn',
            'course' => $this->db->first('SELECT * FROM {courses} WHERE id = ?', [(int) $quiz['course_id']]),
            'quiz' => $quiz,
            'attempt' => $attempt,
            'graded' => $attempt['status'] === 'graded',
            'durationText' => $this->humanDuration($duration),
        ]);
    }

    // ---- ภายใน ----

    private function requireEnrolled(Request $request, int $courseId, int $studentId): void
    {
        if (!$this->enrollments->isEnrolled($courseId, $studentId)) {
            throw new HttpForbiddenException($request, 'คุณไม่ได้ลงทะเบียนในรายวิชานี้');
        }
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} */
    private function requireOwnAttempt(Request $request, int $attemptId, int $studentId): array
    {
        $attempt = $this->attempts->find($attemptId);
        if ($attempt === null || (int) $attempt['student_id'] !== $studentId) {
            throw new HttpNotFoundException($request, 'ไม่พบการทำแบบทดสอบนี้');
        }
        $quiz = $this->quizzes->find((int) $attempt['quiz_id']);
        if ($quiz === null) {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }

        return [$attempt, $quiz];
    }

    private function humanDuration(int $seconds): string
    {
        $seconds = max(0, $seconds);
        $m = intdiv($seconds, 60);
        $s = $seconds % 60;

        return $m > 0 ? sprintf('%d นาที %d วินาที', $m, $s) : sprintf('%d วินาที', $s);
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
