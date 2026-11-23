<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;

final class LoginAttempt
{
    public static function record(?int $userId, string $email, string $ip, string $userAgent, bool $successful, ?string $reason = null): void
    {
        App::db()->insert('login_attempts', [
            'user_id' => $userId,
            'email' => mb_substr(mb_strtolower(trim($email)), 0, 191),
            'ip_address' => $ip,
            'user_agent' => mb_substr($userAgent, 0, 500),
            'successful' => $successful ? 1 : 0,
            'failure_reason' => $reason,
            'attempted_at' => now(),
        ]);
    }

    public static function recentFailuresFromIp(string $ip, int $minutes): int
    {
        return (int) App::db()->scalar(
            'SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND successful = 0 AND attempted_at > ?',
            [$ip, now("-{$minutes} minutes")]
        );
    }

    /** @param array{q?: string, result?: string, user_id?: int} $filters */
    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['user_id'])) {
            $where[] = 'la.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = "(la.email LIKE ? ESCAPE '!' OR la.ip_address LIKE ? ESCAPE '!')";
            $like = Database::likeContains($filters['q']);
            array_push($params, $like, $like);
        }
        if (($filters['result'] ?? '') === 'success') {
            $where[] = 'la.successful = 1';
        } elseif (($filters['result'] ?? '') === 'failed') {
            $where[] = 'la.successful = 0';
        }
        return Paginator::query(
            App::db(),
            'la.*, u.name AS user_name',
            'FROM login_attempts la LEFT JOIN users u ON u.id = la.user_id WHERE ' . implode(' AND ', $where),
            'la.attempted_at DESC, la.id DESC',
            $params,
            $page,
            $perPage
        );
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 8, ?int $userId = null): array
    {
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT la.*, u.name AS user_name FROM login_attempts la LEFT JOIN users u ON u.id = la.user_id';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE la.user_id = ?';
            $params[] = $userId;
        }
        return App::db()->select($sql . " ORDER BY la.attempted_at DESC, la.id DESC LIMIT {$limit}", $params);
    }

    /**
     * Successful and failed logins per day for the last N days (UTC dates).
     * @return array{labels: list<string>, success: list<int>, failed: list<int>}
     */
    public static function dailyCounts(int $days = 14): array
    {
        $since = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $rows = App::db()->select(
            'SELECT SUBSTR(attempted_at, 1, 10) AS day, successful, COUNT(*) AS n FROM login_attempts
             WHERE attempted_at >= ? GROUP BY SUBSTR(attempted_at, 1, 10), successful',
            [$since]
        );
        $map = [];
        foreach ($rows as $r) {
            $map[$r['day']][(int) $r['successful']] = (int) $r['n'];
        }
        $out = ['labels' => [], 'success' => [], 'failed' => []];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = gmdate('Y-m-d', strtotime("-{$i} days"));
            $out['labels'][] = gmdate('d M', strtotime($day));
            $out['success'][] = $map[$day][1] ?? 0;
            $out['failed'][] = $map[$day][0] ?? 0;
        }
        return $out;
    }
}
