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
                    (SELECT COUNT(*) FROM {units} u WHERE u.course_id = c.id) AS unit_count
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
                    (SELECT COUNT(*) FROM {units} u WHERE u.course_id = c.id) AS unit_count
             FROM {courses} c
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             WHERE c.id = ?',
            [$id]
        );
    }

    /**
     * เพิ่มรายวิชาใหม่ให้ครูคนนี้
     *
     * @param array<string,mixed> $data
     */
    public function create(int $teacherId, array $data): int
    {
        return $this->db->insert('courses', $data + [
            'teacher_id' => $teacherId,
            'status' => 'active',
        ]);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->update('courses', $data, ['id' => $id]);
    }

    /** เก็บรายวิชาเข้าคลัง (ไม่ลบทิ้ง เพราะบทเรียนและคะแนนยังผูกอยู่) */
    public function archive(int $id): void
    {
        $this->db->update('courses', ['status' => 'archived'], ['id' => $id]);
    }

    /** รหัสวิชาซ้ำกับรายวิชาอื่นในภาคเรียน/กลุ่มเรียนเดียวกันหรือไม่ */
    public function codeTaken(string $code, ?int $termId, ?int $classroomId, ?int $exceptId = null): bool
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {courses}
             WHERE code = ? AND term_id <=> ? AND classroom_id <=> ? AND id <> ?',
            [$code, $termId, $classroomId, $exceptId ?? 0]
        ) > 0;
    }

    /** @return list<array<string,mixed>> ภาคเรียนทั้งหมด ใหม่สุดขึ้นก่อน */
    public function terms(): array
    {
        return $this->db->all(
            'SELECT id, academic_year, semester, name, is_current
             FROM {academic_terms} ORDER BY academic_year DESC, semester DESC'
        );
    }

    /** @return list<array<string,mixed>> กลุ่มเรียนพร้อมชื่อสาขา */
    public function classrooms(): array
    {
        return $this->db->all(
            'SELECT cr.id, cr.name, d.name AS department_name
             FROM {classrooms} cr LEFT JOIN {departments} d ON d.id = cr.department_id
             ORDER BY cr.level, cr.year_level, cr.name'
        );
    }

    public function currentTermId(): ?int
    {
        $id = $this->db->int('SELECT id FROM {academic_terms} WHERE is_current = 1 ORDER BY id DESC LIMIT 1');

        return $id > 0 ? $id : null;
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

    /** @return list<array<string,mixed>> รายวิชาที่ยังเปิดใช้งานทั้งหมด สำหรับผู้ดูแลตั้งค่าฟังก์ชัน AI */
    public function allActiveForAdmin(): array
    {
        return $this->db->all(
            'SELECT c.id, c.code, c.name, c.ai_quiz_enabled, c.ai_lesson_plan_enabled,
                    u.full_name AS teacher_name, cr.name AS classroom_name
             FROM {courses} c
             JOIN {users} u ON u.id = c.teacher_id
             LEFT JOIN {classrooms} cr ON cr.id = c.classroom_id
             WHERE c.status = \'active\'
             ORDER BY u.full_name, c.code'
        );
    }

    /** เปิด/ปิดฟังก์ชัน AI ของรายวิชานี้ */
    public function setAiFeatures(int $id, bool $quizEnabled, bool $lessonPlanEnabled): void
    {
        $this->db->update('courses', [
            'ai_quiz_enabled' => $quizEnabled ? 1 : 0,
            'ai_lesson_plan_enabled' => $lessonPlanEnabled ? 1 : 0,
        ], ['id' => $id]);
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
                    (SELECT COUNT(*) FROM {units} u2 WHERE u2.course_id = c.id AND u2.review_status = \'published\') AS unit_count
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
