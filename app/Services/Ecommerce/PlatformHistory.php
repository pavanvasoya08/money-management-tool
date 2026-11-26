<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Core\Database;

/** Insert-only history of a platform: creation, changes, configuration, status, mappings, documents, notes, archiving. */
final class PlatformHistory
{
    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new @param array<string, mixed> $actor */
    public static function write(Database $db, int $platformId, string $event, ?array $old = null, ?array $new = null, ?string $reason = null, array $actor = [],
        ?string $from = null, ?string $to = null, ?string $date = null): void
    {
        $db->insert('ecommerce_platform_history', [
            'platform_id' => $platformId, 'event' => $event, 'from_status' => $from, 'to_status' => $to,
            'old_values' => $old === null || $old === [] ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null || $new === [] ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null && trim($reason) !== '' ? mb_substr($reason, 0, 500) : null,
            'effective_date' => $date, 'actor_id' => isset($actor['id']) ? (int) $actor['id'] : null, 'created_at' => now(),
        ]);
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> newest first */
    public static function read(Database $db, string $where, array $params, int $limit = 300): array
    {
        $rows = $db->select("SELECT h.*, u.name AS actor_name, p.platform_code, p.name AS platform_name FROM ecommerce_platform_history h
            JOIN ecommerce_platforms p ON p.id = h.platform_id LEFT JOIN users u ON u.id = h.actor_id WHERE {$where} ORDER BY h.id DESC LIMIT " . max(1, $limit), $params);
        foreach ($rows as &$r) {
            $r['old_values'] = $r['old_values'] !== null ? json_decode((string) $r['old_values'], true) : null;
            $r['new_values'] = $r['new_values'] !== null ? json_decode((string) $r['new_values'], true) : null;
        }
        return $rows;
    }

    /** Human label for an event key. */
    public static function label(string $event): string
    {
        return ucfirst(str_replace('_', ' ', $event));
    }
}
