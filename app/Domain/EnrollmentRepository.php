<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * นักเรียนที่ลงทะเบียนในรายวิชา และสถานะการส่งงาน
 */
final class EnrollmentRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function studentsInCourse(int $courseId): array
    {
        return $this->db->all(
            'SELECT u.id, u.username, u.full_name, e.status AS enrollment_status
             FROM {enrollments} e
             JOIN {users} u ON u.id = e.student_id
             WHERE e.course_id = ? AND e.status = \'active\'
             ORDER BY u.username',
            [$courseId]
        );
    }

    /** @return list<int> */
    public function studentIds(int $courseId): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['student_id'],
            $this->db->all('SELECT student_id FROM {enrollments} WHERE course_id = ? AND status = \'active\'', [$courseId])
        );
    }

    public function isEnrolled(int $courseId, int $studentId): bool
    {
        return $this->db->int(
            'SELECT COUNT(*) FROM {enrollments} WHERE course_id = ? AND student_id = ? AND status = \'active\'',
            [$courseId, $studentId]
        ) > 0;
    }

    /** @return list<array<string,mixed>> รายวิชาที่นักเรียนคนนี้เรียนอยู่ */
    public function coursesForStudent(int $studentId): array
    {
        return $this->db->all(
            'SELECT c.*, u.full_name AS teacher_name
             FROM {enrollments} e
             JOIN {courses} c ON c.id = e.course_id
             LEFT JOIN {users} u ON u.id = c.teacher_id
             WHERE e.student_id = ? AND e.status = \'active\' AND c.status = \'active\'
             ORDER BY c.code',
            [$studentId]
        );
    }
}
