<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * รายวิชา: การดึงรายวิชาของครู จำนวนนักเรียน และข้อมูลสรุปที่หน้าแดชบอร์ด/หน้ารายวิชาต้องใช้
 */
final class CourseRepository
{
    /**
     * สีหัวการ์ดรายวิชา (ไม่มี # เพราะ Latte จะ escape # ใน context ของ style attribute
     * เทมเพลตจึงเติม # เองเป็น style="background:#{$c['color']}")
     */
    private const CARD_COLORS = ['0E6B60', '2D6E8E', '6B5B95', '8A5A3B', '1F5F3F', '9C6206'];

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

    /** ครูคนนี้เปิดให้แสดงรายวิชาในหน้าแรกสาธารณะหรือไม่ (ถือว่าเปิดถ้ามีอย่างน้อยหนึ่งวิชาที่ตั้งไว้) */
    public function landingEnabledForTeacher(int $teacherId): bool
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {courses} WHERE teacher_id = ? AND status = \'active\' AND show_on_landing = 1',
            [$teacherId]
        ) > 0;
    }

    /** ตั้งค่าแสดง/ไม่แสดงรายวิชาทั้งหมดของครูในหน้าแรก */
    public function setLandingForTeacher(int $teacherId, bool $visible): int
    {
        return $this->db->run(
            'UPDATE {courses} SET show_on_landing = ? WHERE teacher_id = ? AND status = \'active\'',
            [$visible ? 1 : 0, $teacherId]
        );
    }

    /**
     * รายวิชาที่ครูเลือกให้แสดงในหน้าแรกสาธารณะ จัดกลุ่มตามสาขาวิชา
     *
     * @return list<array{department:string,courses:list<array<string,mixed>>}>
     */
    public function publicLanding(): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.code, c.name, c.credits, c.theory_hours, c.practice_hours, c.description,
                    cr.name AS classroom_name,
                    d.name AS department_name,
                    u.full_name AS teacher_name,
                    (SELECT COUNT(*) FROM {enrollments} e WHERE e.course_id = c.id AND e.status = \'active\') AS student_count,
                    (SELECT COUNT(*) FROM {lessons} l WHERE l.course_id = c.id AND l.review_status = \'published\') AS lesson_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             LEFT JOIN {departments} d ON d.id = cr.department_id
             LEFT JOIN {users} u ON u.id = c.teacher_id
             WHERE c.show_on_landing = 1 AND c.status = \'active\'
             ORDER BY d.name, c.code'
        );

        $groups = [];
        $n = 0;
        foreach ($rows as $row) {
            $key = (string) ($row['department_name'] ?? 'รายวิชาอื่น ๆ');
            $groups[$key] ??= ['department' => $key, 'courses' => []];
            $row['color'] = self::CARD_COLORS[$n++ % count(self::CARD_COLORS)];
            $groups[$key]['courses'][] = $row;
        }

        return array_values($groups);
    }
}
