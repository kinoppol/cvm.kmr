<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * รายวิชา: การดึงรายวิชาของครู จำนวนนักเรียน และข้อมูลสรุปที่หน้าแดชบอร์ด/หน้ารายวิชาต้องใช้
 */
final class CourseRepository
{
    /** สีหัวการ์ดรายวิชาบนแดชบอร์ด วนซ้ำตามลำดับ */
    private const CARD_COLORS = ['#0E6B60', '#2D6E8E', '#6B5B95', '#8A5A3B', '#1F5F3F', '#9C6206'];

    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> รายวิชาที่ครูคนนี้สอนในภาคเรียนปัจจุบัน */
    public function forTeacher(int $teacherId): array
    {
        $rows = $this->db->all(
            'SELECT c.*, cr.name AS classroom_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {lessons} l WHERE l.course_id = c.id) AS lesson_count,
                    (SELECT COUNT(*) FROM {quizzes} q WHERE q.course_id = c.id) AS quiz_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             WHERE c.teacher_id = ? AND c.status = \'active\'
             ORDER BY c.code',
            [$teacherId]
        );

        foreach ($rows as $i => $row) {
            $rows[$i]['color'] = self::CARD_COLORS[$i % count(self::CARD_COLORS)];
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT c.*, cr.name AS classroom_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {lessons} l WHERE l.course_id = c.id) AS lesson_count,
                    (SELECT COUNT(*) FROM {quizzes} q WHERE q.course_id = c.id) AS quiz_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             WHERE c.id = ?',
            [$id]
        );
    }

    /** ครูเป็นเจ้าของรายวิชานี้หรือไม่ */
    public function ownedByTeacher(int $courseId, int $teacherId): bool
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {courses} WHERE id = ? AND teacher_id = ?',
            [$courseId, $teacherId]
        ) > 0;
    }

    public function color(int $index): string
    {
        return self::CARD_COLORS[$index % count(self::CARD_COLORS)];
    }
}
