<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * แบบทดสอบ ข้อสอบ และตัวเลือก — รวมถึงการบันทึกร่างจากผู้ช่วย AI
 */
final class QuizRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forCourse(int $courseId): array
    {
        return $this->db->all(
            'SELECT q.*,
                    (SELECT COUNT(*) FROM {quiz_questions} qq WHERE qq.quiz_id = q.id) AS question_count,
                    (SELECT COUNT(DISTINCT a.student_id) FROM {quiz_attempts} a WHERE a.quiz_id = q.id AND a.status <> \'in_progress\') AS submitted_count
             FROM {quizzes} q
             WHERE q.course_id = ?
             ORDER BY q.created_at DESC',
            [$courseId]
        );
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {quizzes} WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> ข้อสอบพร้อมตัวเลือกของแบบทดสอบ */
    public function questions(int $quizId): array
    {
        $questions = $this->db->all(
            'SELECT * FROM {quiz_questions} WHERE quiz_id = ? ORDER BY sort_order, id',
            [$quizId]
        );

        foreach ($questions as $i => $q) {
            $questions[$i]['choices'] = $this->db->all(
                'SELECT * FROM {quiz_choices} WHERE question_id = ? ORDER BY sort_order, id',
                [(int) $q['id']]
            );
        }

        return $questions;
    }

    /**
     * สร้างแบบทดสอบใหม่พร้อมข้อสอบทั้งหมดในครั้งเดียว
     *
     * @param array<string,mixed> $quiz ข้อมูลหลักของแบบทดสอบ (course_id, title, ...)
     * @param list<array{type:string,question:string,explanation:?string,score:float,source:string,choices:list<array{label:string,content:string,is_correct:bool,match_key?:?string}>}> $questions
     */
    public function createWithQuestions(array $quiz, array $questions): int
    {
        return (int) $this->db->transaction(function (Db $db) use ($quiz, $questions): int {
            $quizId = $db->insert('quizzes', $quiz);

            foreach ($questions as $order => $q) {
                $questionId = $db->insert('quiz_questions', [
                    'quiz_id' => $quizId,
                    'type' => $q['type'],
                    'question' => $q['question'],
                    'explanation' => $q['explanation'] ?? null,
                    'score' => $q['score'] ?? 1.0,
                    'sort_order' => $order,
                    'source' => $q['source'] ?? 'ai',
                ]);

                foreach ($q['choices'] as $ci => $choice) {
                    $db->insert('quiz_choices', [
                        'question_id' => $questionId,
                        'label' => $choice['label'] ?? '',
                        'content' => $choice['content'],
                        'match_key' => $choice['match_key'] ?? null,
                        'is_correct' => !empty($choice['is_correct']) ? 1 : 0,
                        'sort_order' => $ci,
                    ]);
                }
            }

            return $quizId;
        });
    }

    public function setReviewStatus(int $quizId, string $status): void
    {
        $this->db->update('quizzes', ['review_status' => $status], ['id' => $quizId]);
    }
}
