<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;

/**
 * Read access to the append-only audit trail. There is intentionally no
 * update or delete method: audit records cannot be modified through the app.
 * Writes go through App\Services\ActivityLogger.
 */
final class ActivityLog
{
    /** @param array{q?: string, action?: string, user_id?: int, subject_type?: string, subject_id?: int} $filters */
    public static function paginate(array $filters, int $page, int $perPage): Paginator
    {
        $where = ['1 = 1'];
        $params = [];
        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['subject_type']) && !empty($filters['subject_id'])) {
            $where[] = '(a.subject_type = ? AND a.subject_id = ?)';
            array_push($params, $filters['subject_type'], (int) $filters['subject_id']);
        }
        if (($filters['action'] ?? '') !== '') {
            $where[] = "a.action LIKE ? ESCAPE '!'";
            $params[] = Database::likeEscape($filters['action']) . '%';
        }
        if (($filters['q'] ?? '') !== '') {
            $where[] = "(a.description LIKE ? ESCAPE '!' OR u.name LIKE ? ESCAPE '!' OR a.ip_address LIKE ? ESCAPE '!')";
            $like = Database::likeContains($filters['q']);
            array_push($params, $like, $like, $like);
        }
        return Paginator::query(
            App::db(),
            'a.id, a.user_id, a.action, a.description, a.subject_type, a.subject_id, a.ip_address, a.user_agent, a.created_at, u.name AS user_name',
            'FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE ' . implode(' AND ', $where),
            'a.created_at DESC, a.id DESC',
            $params,
            $page,
            $perPage
        );
    }

    /** @return list<array<string, mixed>> */
    public static function recent(int $limit = 8, ?int $userId = null): array
    {
        $limit = max(1, min(50, $limit));
        $sql = 'SELECT a.id, a.action, a.description, a.ip_address, a.created_at, u.name AS user_name
                FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id';
        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE a.user_id = ?';
            $params[] = $userId;
        }
        return App::db()->select($sql . " ORDER BY a.created_at DESC, a.id DESC LIMIT {$limit}", $params);
    }

    /** @return list<string> distinct action prefixes for the filter dropdown */
    public static function actionGroups(): array
    {
        $actions = array_column(App::db()->select('SELECT DISTINCT action FROM activity_logs ORDER BY action'), 'action');
        $groups = array_unique(array_map(static fn (string $a): string => explode('.', $a)[0], $actions));
        sort($groups);
        return array_values($groups);
    }
}
