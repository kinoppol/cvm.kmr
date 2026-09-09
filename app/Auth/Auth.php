<?php

declare(strict_types=1);

namespace App\Auth;

use PDO;

final class Auth
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly PDO $db,
        private readonly string $prefix,
    ) {
    }

    /** @return array{ok:bool,message:string} */
    public function attempt(string $username, string $password, string $ip): array
    {
        if ($this->isThrottled($username, $ip)) {
            return ['ok' => false, 'message' => sprintf(
                'พยายามเข้าสู่ระบบผิดเกิน %d ครั้ง กรุณารอ %d นาทีแล้วลองใหม่',
                self::MAX_ATTEMPTS,
                self::WINDOW_MINUTES
            )];
        }

        $user = $this->findByUsername($username);
        $valid = $user !== null && password_verify($password, $user['password_hash']);

        $this->record($username, $ip, 'login', $valid);

        if (!$valid) {
            return ['ok' => false, 'message' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'];
        }

        if ($user['status'] !== 'active') {
            return ['ok' => false, 'message' => 'บัญชีนี้ถูกระงับการใช้งาน กรุณาติดต่อผู้ดูแลระบบ'];
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $this->updateHash((int) $user['id'], password_hash($password, PASSWORD_DEFAULT));
        }

        $this->start($user);

        return ['ok' => true, 'message' => 'เข้าสู่ระบบสำเร็จ'];
    }

    public function start(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['user_role'] = $user['role'];

        $stmt = $this->db->prepare(sprintf('UPDATE `%susers` SET last_login_at = NOW() WHERE id = ?', $this->prefix));
        $stmt->execute([(int) $user['id']]);
    }

    public function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public function user(): ?array
    {
        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $stmt = $this->db->prepare(sprintf(
            'SELECT id, username, email, full_name, role, status, last_login_at FROM `%susers` WHERE id = ?',
            $this->prefix
        ));
        $stmt->execute([(int) $_SESSION['user_id']]);
        $user = $stmt->fetch();

        if ($user === false || $user['status'] !== 'active') {
            $this->logout();

            return null;
        }

        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = $this->db->prepare(sprintf(
            'SELECT id, username, email, full_name, password_hash, role, status FROM `%susers` WHERE username = ?',
            $this->prefix
        ));
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        return $user === false ? null : $user;
    }

    public function isThrottled(string $username, string $ip, string $scope = 'login'): bool
    {
        $stmt = $this->db->prepare(sprintf(
            'SELECT COUNT(*) FROM `%slogin_attempts`
             WHERE scope = ? AND succeeded = 0 AND attempted_at > (NOW() - INTERVAL ? MINUTE)
               AND (identifier = ? OR ip_address = ?)',
            $this->prefix
        ));
        $stmt->execute([$scope, self::WINDOW_MINUTES, $username, $ip]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function record(string $identifier, string $ip, string $scope, bool $succeeded): void
    {
        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO `%slogin_attempts` (identifier, ip_address, scope, succeeded) VALUES (?, ?, ?, ?)',
            $this->prefix
        ));
        $stmt->execute([mb_substr($identifier, 0, 191), $ip, $scope, $succeeded ? 1 : 0]);
    }

    public function log(string $action, ?string $target = null, array $meta = []): void
    {
        $stmt = $this->db->prepare(sprintf(
            'INSERT INTO `%saudit_logs` (user_id, action, target, meta, ip_address) VALUES (?, ?, ?, ?, ?)',
            $this->prefix
        ));
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            $action,
            $target,
            $meta === [] ? null : json_encode($meta, JSON_UNESCAPED_UNICODE),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    }

    private function updateHash(int $id, string $hash): void
    {
        $stmt = $this->db->prepare(sprintf('UPDATE `%susers` SET password_hash = ? WHERE id = ?', $this->prefix));
        $stmt->execute([$hash, $id]);
    }
}
