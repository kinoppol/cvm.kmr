<?php

declare(strict_types=1);

namespace App\Controllers;

use App\AI\AiRouter;
use App\AI\AiUnavailableException;
use App\AI\QuizGenerator;
use App\Auth\Auth;
use App\Domain\AiRepository;
use App\Domain\CourseRepository;
use App\Domain\LessonRepository;
use App\Domain\QuizRepository;
use App\Domain\ReviewRepository;
use App\Domain\SettingsRepository;
use App\Support\Csrf;
use App\Support\Flash;
use App\Support\Thai;
use App\Support\Url;
use App\Support\View;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Throwable;

/**
 * ตัวช่วยออกข้อสอบจากบทเรียน: ตั้งค่างาน → สตรีมร่างทีละข้อ → ตรวจและแก้ในหน้าเดิม → ยืนยัน
 */
final class QuizWizardController
{
    private const COUNT_OPTIONS = [5, 8, 10, 20];
    private const TYPE_OPTIONS = ['ปรนัย', 'อัตนัย', 'จับคู่'];
    private const LEVELS = [
        'ง่าย' => 'เน้นความจำและคำศัพท์พื้นฐาน',
        'กลาง' => 'ความเข้าใจและการนำไปใช้ในงานจริง',
        'ยาก' => 'วิเคราะห์อาการเสียและแก้ปัญหา',
    ];

    public function __construct(
        private readonly View $view,
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly QuizRepository $quizzes,
        private readonly ReviewRepository $reviews,
        private readonly AiRepository $ai,
        private readonly AiRouter $router,
        private readonly QuizGenerator $generator,
        private readonly SettingsRepository $settings,
        private readonly Auth $auth,
    ) {
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $lessons = $this->lessons->forCourse($course['id']);

        $route = $this->tryRoute((int) $user['id']);

        return $this->view->render($response, 'quiz/create', [
            'page' => 'courses',
            'course' => $course,
            'lessons' => $lessons,
            'countOptions' => self::COUNT_OPTIONS,
            'typeOptions' => self::TYPE_OPTIONS,
            'levels' => self::LEVELS,
            'aiChip' => $route['chip'],
            'aiWait' => $route['wait'],
            'aiError' => $route['error'],
        ]);
    }

    public function workspace(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $params = $this->readParams($request, $course);

        if ($params['lessonTitles'] === [] || $params['types'] === []) {
            Flash::error('กรุณาเลือกบทเรียนและชนิดข้อสอบอย่างน้อยหนึ่งอย่าง');

            return $this->redirect($response, "/courses/{$course['id']}/quizzes/create");
        }

        $route = $this->tryRoute((int) $user['id']);

        return $this->view->render($response, 'quiz/workspace', [
            'page' => 'courses',
            'course' => $course,
            'params' => $params,
            'query' => $request->getUri()->getQuery(),
            'aiChip' => $route['chip'],
        ]);
    }

    /** SSE: สตรีมร่างข้อสอบทีละข้อ แล้วบันทึกเป็นแบบทดสอบฉบับร่าง */
    public function stream(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $params = $this->readParams($request, $course);
        $quality = ($request->getQueryParams()['quality'] ?? 'fast') === 'quality' ? 'quality' : 'fast';

        ignore_user_abort(true);
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');

        $send = static function (string $event, array $data): void {
            echo 'event: ' . $event . "\n";
            echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };

        try {
            $route = $this->router->route((int) $user['id'], $quality);
        } catch (AiUnavailableException $e) {
            $send('error', ['reason' => $e->reason, 'message' => $e->getMessage()]);

            return $response;
        }

        $send('meta', [
            'chip' => $route->chip,
            'total' => $params['count'],
            'source' => $route->source,
        ]);

        $jobId = $this->ai->createJob([
            'user_id' => (int) $user['id'],
            'course_id' => $course['id'],
            'kind' => 'quiz',
            'source' => $route->source,
            'status' => 'running',
            'prompt' => json_encode($params, JSON_UNESCAPED_UNICODE),
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        $collected = [];
        $started = hrtime(true);

        try {
            foreach ($this->generator->stream($route, [
                'course_code' => $course['code'],
                'course_name' => $course['name'],
                'lesson_titles' => $params['lessonTitles'],
                'count' => $params['count'],
                'types' => $params['types'],
                'level' => $params['level'],
            ], $quality) as $ev) {
                if ($ev['event'] === 'question') {
                    $collected[] = $ev['data'];
                    $send('question', $ev['data']);
                } elseif ($ev['event'] === 'error') {
                    $send('error', ['reason' => 'generate_failed', 'message' => $ev['message']]);
                    $this->ai->updateJob($jobId, ['status' => 'failed', 'error' => $ev['message'], 'finished_at' => date('Y-m-d H:i:s')]);

                    return $response;
                }
            }
        } catch (Throwable $e) {
            $send('error', ['reason' => 'generate_failed', 'message' => 'ระบบขัดข้องระหว่างร่างข้อสอบ']);
            $this->ai->updateJob($jobId, ['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => date('Y-m-d H:i:s')]);

            return $response;
        }

        if ($collected === []) {
            $send('error', ['reason' => 'empty', 'message' => 'ผู้ช่วยไม่ได้ร่างข้อสอบออกมา กรุณาลองใหม่']);
            $this->ai->updateJob($jobId, ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s')]);

            return $response;
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);
        $quizId = $this->persist($course, $params, $collected, (int) $user['id'], $jobId, $route->source, $durationMs);

        if ($route->countsQuota) {
            $this->ai->consumeQuota((int) $user['id'], $this->period(), $this->settings->int('ai_monthly_quota', 60));
        }
        $this->ai->logUsage((int) $user['id'], $jobId, $route->source, $route->model, true);
        $this->auth->log('ai.quiz.generate', 'quiz#' . $quizId, ['count' => count($collected), 'source' => $route->source]);

        $send('done', [
            'quizId' => $quizId,
            'total' => count($collected),
            'reviewUrl' => Url::to("/courses/{$course['id']}/quizzes/$quizId/review"),
        ]);

        return $response;
    }

    public function review(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);

        $questions = $this->quizzes->questions($quiz['id']);
        $generation = $this->reviews->findByTarget((int) $user['id'], 'quiz', $quiz['id']);
        $genPayload = $generation ? (json_decode((string) $generation['payload'], true) ?: []) : [];

        $regenQuery = '';
        if (isset($genPayload['params'])) {
            $p = $genPayload['params'];
            $qs = [];
            foreach ($p['lessons'] ?? [] as $id) {
                $qs[] = 'lessons=' . (int) $id;
            }
            foreach ($p['types'] ?? [] as $t) {
                $qs[] = 'types=' . rawurlencode($t);
            }
            $qs[] = 'count=' . (int) ($p['count'] ?? 8);
            $qs[] = 'level=' . rawurlencode((string) ($p['level'] ?? 'กลาง'));
            $regenQuery = implode('&', $qs);
        }

        return $this->view->render($response, 'quiz/review', [
            'page' => 'courses',
            'course' => $course,
            'quiz' => $quiz,
            'questions' => $questions,
            'aiChip' => $this->sourceChip($generation),
            'createdAgo' => Thai::ago($quiz['created_at']),
            'regenQuery' => $regenQuery,
        ]);
    }

    public function regenerateOne(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);
        $question = $this->quizzes->getQuestion((int) $args['qid']);

        if ($question === null || (int) $question['quiz_id'] !== (int) $quiz['id']) {
            throw new HttpNotFoundException($request, 'ไม่พบข้อสอบนี้');
        }

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['error' => 'เซสชันหมดอายุ'], 419);
        }

        $generation = $this->reviews->findByTarget((int) $user['id'], 'quiz', (int) $quiz['id']);
        $genPayload = $generation ? (json_decode((string) $generation['payload'], true) ?: []) : [];
        $params = $genPayload['params'] ?? [];

        try {
            $route = $this->router->route((int) $user['id']);
        } catch (AiUnavailableException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 503);
        }

        $fresh = null;
        foreach ($this->generator->stream($route, [
            'course_code' => $course['code'],
            'course_name' => $course['name'],
            'lesson_titles' => $params['lessonTitles'] ?? [],
            'count' => 3,
            'types' => [$question['type'] === 'short_answer' ? 'อัตนัย' : 'ปรนัย'],
            'level' => $params['level'] ?? 'กลาง',
        ]) as $ev) {
            if ($ev['event'] === 'question') {
                $fresh = $ev['data'];
                if ($fresh['question'] !== $question['question']) {
                    break;
                }
            }
        }

        if ($fresh === null) {
            return $this->json($response, ['error' => 'ร่างข้อใหม่ไม่สำเร็จ'], 500);
        }

        $this->quizzes->replaceQuestion((int) $question['id'], $fresh);
        $this->ai->logUsage((int) $user['id'], null, $route->source, $route->model, true);

        return $this->json($response, ['question' => $this->quizzes->getQuestion((int) $question['id'])]);
    }

    public function save(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            Flash::error('เซสชันหมดอายุ กรุณาลองใหม่อีกครั้ง');

            return $this->redirect($response, "/courses/{$course['id']}/quizzes/{$quiz['id']}/review");
        }

        $payload = json_decode((string) ($data['payload'] ?? '[]'), true);
        if (is_array($payload)) {
            $this->quizzes->saveEdits((int) $quiz['id'], $payload);
        }

        $title = trim((string) ($data['title'] ?? '')) ?: $quiz['title'];
        $publish = ($data['action'] ?? '') === 'publish';

        $this->quizzes->updateQuiz((int) $quiz['id'], [
            'title' => $title,
            'time_limit_minutes' => (int) ($data['time_limit'] ?? 0) ?: null,
            'review_status' => $publish ? 'published' : 'pending',
        ]);

        $generation = $this->reviews->findByTarget((int) $user['id'], 'quiz', (int) $quiz['id']);
        if ($generation !== null && $publish) {
            $this->reviews->approve((int) $generation['id'], (int) $user['id']);
        }

        $this->auth->log($publish ? 'quiz.publish' : 'quiz.draft', 'quiz#' . $quiz['id']);

        if ($publish) {
            Flash::success('บันทึกเป็นแบบทดสอบแล้ว · ตั้งเวลาเปิดให้นักเรียนได้ในแท็บแบบทดสอบ');

            return $this->redirect($response, "/courses/{$course['id']}?tab=quizzes");
        }

        Flash::success('เก็บไว้ในรายการรอตรวจแล้ว');

        return $this->redirect($response, '/review');
    }

    public function deleteQuestion(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $course = $this->ownedCourse($request, (int) $args['courseId'], (int) $user['id']);
        $quiz = $this->ownedQuiz($request, (int) $args['quizId'], $course['id']);

        $data = (array) $request->getParsedBody();
        if (!Csrf::check($data['_token'] ?? null)) {
            return $this->json($response, ['error' => 'เซสชันหมดอายุ'], 419);
        }

        $this->quizzes->deleteQuestion((int) $quiz['id'], (int) $args['qid']);

        return $this->json($response, ['ok' => true, 'remaining' => $this->quizzes->questionCount((int) $quiz['id'])]);
    }

    // ---------------------------------------------------------------- ภายใน

    private function persist(array $course, array $params, array $questions, int $userId, int $jobId, string $source, int $durationMs): int
    {
        $title = sprintf('ร่างข้อสอบ · %s%s', $course['name'], $this->lessonRange($params['lessonTitles']));

        $quizId = $this->quizzes->createWithQuestions([
            'course_id' => $course['id'],
            'title' => $title,
            'instructions' => null,
            'attempts_allowed' => 1,
            'source' => 'ai',
            'review_status' => 'draft',
            'created_by' => $userId,
        ], array_map(static fn (array $q): array => $q + ['source' => 'ai'], $questions));

        $this->ai->updateJob($jobId, [
            'status' => 'done',
            'duration_ms' => $durationMs,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);

        $genId = $this->reviews->record($userId, $jobId, 'quiz', $quizId, [
            'title' => $title,
            'summary' => sprintf('%d ข้อ · %s', count($questions), implode(', ', $params['types'])),
            'ai_mode' => $source === 'byok' ? 'โหมดเร็ว' : null,
            'params' => $params,
        ]);
        $this->reviews->linkTarget($genId, 'quiz', $quizId);

        return $quizId;
    }

    /** @return array{lessons:list<int>,lessonTitles:list<string>,count:int,types:list<string>,level:string} */
    private function readParams(Request $request, array $course): array
    {
        $q = $request->getQueryParams();
        $lessonIds = array_values(array_filter(array_map('intval', (array) ($q['lessons'] ?? []))));

        $lessons = $lessonIds === [] ? [] : $this->lessons->byIds($lessonIds);
        // เก็บเฉพาะบทเรียนของรายวิชานี้
        $lessons = array_values(array_filter($lessons, static fn (array $l): bool => (int) $l['course_id'] === (int) $course['id']));

        $types = array_values(array_intersect(self::TYPE_OPTIONS, (array) ($q['types'] ?? [])));
        $count = in_array((int) ($q['count'] ?? 0), self::COUNT_OPTIONS, true) ? (int) $q['count'] : 8;
        $level = array_key_exists((string) ($q['level'] ?? ''), self::LEVELS) ? (string) $q['level'] : 'กลาง';

        return [
            'lessons' => array_map(static fn (array $l): int => (int) $l['id'], $lessons),
            'lessonTitles' => array_map(static fn (array $l): string => $l['title'], $lessons),
            'count' => $count,
            'types' => $types ?: ['ปรนัย'],
            'level' => $level,
        ];
    }

    private function lessonRange(array $titles): string
    {
        if ($titles === []) {
            return '';
        }
        if (count($titles) === 1) {
            return ' · ' . $titles[0];
        }

        return sprintf(' · %d บทเรียน', count($titles));
    }

    /** @return array{chip:string,wait:string,error:?array{reason:string,message:string}} */
    private function tryRoute(int $userId): array
    {
        try {
            $route = $this->router->route($userId);

            return ['chip' => $route->chip, 'wait' => $route->wait, 'error' => null];
        } catch (AiUnavailableException $e) {
            return [
                'chip' => 'ผู้ช่วย AI ยังใช้งานไม่ได้',
                'wait' => $e->getMessage(),
                'error' => ['reason' => $e->reason, 'message' => $e->getMessage()],
            ];
        }
    }

    private function sourceChip(?array $generation): string
    {
        if ($generation === null) {
            return 'AI วิทยาลัย';
        }
        $payload = json_decode((string) ($generation['payload'] ?? '{}'), true) ?: [];

        return isset($payload['ai_mode']) && $payload['ai_mode']
            ? 'AI ของฉัน · ' . $payload['ai_mode']
            : 'AI วิทยาลัย';
    }

    private function period(): string
    {
        return sprintf('%04d-%02d', $this->settings->int('academic_year', (int) date('Y')), (int) date('n'));
    }

    /** @return array<string,mixed> */
    private function ownedCourse(Request $request, int $courseId, int $teacherId): array
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

    /** @return array<string,mixed> */
    private function ownedQuiz(Request $request, int $quizId, int $courseId): array
    {
        $quiz = $this->quizzes->find($quizId);
        if ($quiz === null || (int) $quiz['course_id'] !== $courseId) {
            throw new HttpNotFoundException($request, 'ไม่พบแบบทดสอบนี้');
        }

        return $quiz;
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
