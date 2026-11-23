<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Database;
use App\Core\Request;

/**
 * Server-side registry of logins (user_sessions). Each login gets a random
 * secret kept in the PHP session; only its SHA-256 is stored. Revoking a row
 * (force logout, deactivation, password change) ends that session on its very
 * next request — no matter which device or browser it is on.
 */
final class SessionService
{
    private const TOUCH_INTERVAL = 60; // seconds between last_activity updates

    public function __construct(private readonly Database $db)
    {
    }

    /** @return string secret to store in the PHP session */
    public function start(int $userId, Request $request, bool $viaRemember = false): string
    {
        $secret = bin2hex(random_bytes(32));
        $now = now();
        $this->db->insert('user_sessions', [
            'user_id' => $userId,
            'session_hash' => hash('sha256', $secret),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'via_remember' => $viaRemember ? 1 : 0,
            'created_at' => $now,
            'last_activity_at' => $now,
        ]);
        $this->enforceLimit($userId);
        return $secret;
    }

    /** @return array<string, mixed>|null the active (not revoked) session row */
    public function find(?string $secret): ?array
    {
        if ($secret === null || !preg_match('/^[a-f0-9]{64}$/', $secret)) {
            return null;
        }
        return $this->db->first('SELECT * FROM user_sessions WHERE session_hash = ?', [hash('sha256', $secret)]);
    }

    /** @param array<string, mixed> $row */
    public function touch(array $row, Request $request): void
    {
        if (strtotime((string) $row['last_activity_at'] . ' UTC') < time() - self::TOUCH_INTERVAL) {
            $this->db->update('user_sessions', ['last_activity_at' => now(), 'ip_address' => $request->ip()], ['id' => $row['id']]);
        }
    }

    public function revoke(int $sessionId, ?int $actorId, string $reason): void
    {
        $this->db->execute(
            'UPDATE user_sessions SET revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE id = ? AND revoked_at IS NULL',
            [now(), $actorId, mb_substr($reason, 0, 100), $sessionId]
        );
    }

    /** @return int number of sessions revoked */
    public function revokeAllForUser(int $userId, ?int $actorId, string $reason, ?int $exceptSessionId = null): int
    {
        $sql = 'UPDATE user_sessions SET revoked_at = ?, revoked_by = ?, revoke_reason = ? WHERE user_id = ? AND revoked_at IS NULL';
        $params = [now(), $actorId, mb_substr($reason, 0, 100), $userId];
        if ($exceptSessionId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptSessionId;
        }
        return $this->db->execute($sql, $params);
    }

    /** Sessions that are not revoked and not past the absolute/idle lifetime. @return list<array<string, mixed>> */
    public function active(?int $userId = null): array
    {
        $idle = (int) App::settings()->get('session_idle_minutes', 30);
        $sql = 'SELECT s.*, u.name AS user_name, u.email AS user_email FROM user_sessions s JOIN users u ON u.id = s.user_id
                WHERE s.revoked_at IS NULL AND s.last_activity_at > ?';
        $params = [now("-{$idle} minutes")];
        if ($userId !== null) {
            $sql .= ' AND s.user_id = ?';
            $params[] = $userId;
        }
        return $this->db->select($sql . ' ORDER BY s.last_activity_at DESC', $params);
    }

    public function countActive(): int
    {
        return count($this->active());
    }

    private function enforceLimit(int $userId): void
    {
        $max = (int) App::settings()->get('max_concurrent_sessions', 0);
        if ($max <= 0) {
            return;
        }
        $rows = $this->db->select('SELECT id FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY id DESC', [$userId]);
        foreach (array_slice($rows, $max) as $row) {
            $this->revoke((int) $row['id'], null, 'Session limit reached (signed in elsewhere)');
        }
    }
}
