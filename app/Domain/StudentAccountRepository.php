<?php

declare(strict_types=1);

namespace App\Domain;

use App\Support\Db;

/**
 * บัญชีนักเรียนที่ครูสร้าง/นำเข้าเอง — ใช้ร่วมกันได้ระหว่างครูในสถานศึกษาเดียวกัน (institution_id เดียวกัน)
 * ชื่อผู้ใช้คือรหัสนักศึกษา (unique เฉพาะภายในสถานศึกษา) รหัสผ่านคือเลขประจำตัวประชาชน
 */
final class StudentAccountRepository
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function forInstitution(int $institutionId, string $search = ''): array
    {
        if ($search !== '') {
            return $this->db->all(
                "SELECT id, username, full_name, email, phone, status, created_at
                 FROM {users}
                 WHERE institution_id = ? AND role = 'student'
                   AND (username LIKE ? OR full_name LIKE ?)
                 ORDER BY username",
                [$institutionId, "%$search%", "%$search%"]
            );
        }

        return $this->db->all(
            "SELECT id, username, full_name, email, phone, status, created_at
             FROM {users} WHERE institution_id = ? AND role = 'student'
             ORDER BY username",
            [$institutionId]
        );
    }

    public function find(int $id, int $institutionId): ?array
    {
        return $this->db->first(
            "SELECT id, username, full_name, email, phone, status
             FROM {users} WHERE id = ? AND institution_id = ? AND role = 'student'",
            [$id, $institutionId]
        );
    }

    public function usernameTaken(int $institutionId, string $username, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            return $this->db->int(
                'SELECT COUNT(*) FROM {users} WHERE institution_id = ? AND username = ? AND id <> ?',
                [$institutionId, $username, $excludeId]
            ) > 0;
        }

        return $this->db->int(
            'SELECT COUNT(*) FROM {users} WHERE institution_id = ? AND username = ?',
            [$institutionId, $username]
        ) > 0;
    }

    /**
     * @param array{username:string,full_name:string,national_id:string,email:?string,phone:?string} $data
     */
    public function create(int $institutionId, array $data): int
    {
        return $this->db->insert('users', [
            'username' => $data['username'],
            'email' => $data['email'] ?: null,
            'password_hash' => password_hash($data['national_id'], PASSWORD_DEFAULT),
            'full_name' => $data['full_name'],
            'role' => 'student',
            'status' => 'active',
            'phone' => $data['phone'] ?: null,
            'institution_id' => $institutionId,
        ]);
    }

    /**
     * แก้ไขข้อมูลนักเรียน — ถ้าส่ง national_id มาด้วยจะรีเซ็ตรหัสผ่านกลับเป็นเลขประจำตัวประชาชนนั้น
     *
     * @param array{full_name:string,email:?string,phone:?string,national_id?:string} $data
     */
    public function update(int $id, array $data): void
    {
        $fields = [
            'full_name' => $data['full_name'],
            'email' => $data['email'] ?: null,
            'phone' => $data['phone'] ?: null,
        ];

        if (!empty($data['national_id'])) {
            $fields['password_hash'] = password_hash($data['national_id'], PASSWORD_DEFAULT);
        }

        $this->db->update('users', $fields, ['id' => $id]);
    }
}
