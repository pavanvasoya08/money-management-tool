<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\Database;

/**
 * Writes and reads the per-record history tables (product_history, supplier_history):
 * event, status change, old/new values, reason, effective date, who and when. Rows are
 * only ever inserted. Values passed in must already be safe to keep (no full bank numbers).
 */
final class HistoryWriter
{
    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new @param array<string, mixed> $actor */
    public static function write(Database $db, string $table, string $column, int $id, string $event, ?string $from, ?string $to, ?array $old, ?array $new,
        ?string $reason, ?string $date, array $actor, ?int $variantId = null): void
    {
        $row = [
            $column => $id, 'event' => $event, 'from_status' => $from, 'to_status' => $to,
            'old_values' => $old === null || $old === [] ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null || $new === [] ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null && trim($reason) !== '' ? mb_substr($reason, 0, 500) : null,
            'effective_date' => $date, 'actor_id' => isset($actor['id']) ? (int) $actor['id'] : null, 'created_at' => now(),
        ];
        if ($table === 'product_history') {
            $row['variant_id'] = $variantId;
        }
        $db->insert($table, $row);
    }

    /** @return list<array<string, mixed>> newest first */
    public static function read(Database $db, string $table, string $column, int $id, int $limit = 250): array
    {
        $variant = $table === 'product_history' ? ', v.name AS variant_name, v.variant_code' : '';
        $join = $table === 'product_history' ? ' LEFT JOIN product_variants v ON v.id = h.variant_id' : '';
        $rows = $db->select("SELECT h.*, u.name AS actor_name{$variant} FROM {$table} h LEFT JOIN users u ON u.id = h.actor_id{$join} WHERE h.{$column} = ? ORDER BY h.id DESC LIMIT " . max(1, $limit), [$id]);
        foreach ($rows as &$r) {
            $r['old_values'] = $r['old_values'] !== null ? json_decode((string) $r['old_values'], true) : null;
            $r['new_values'] = $r['new_values'] !== null ? json_decode((string) $r['new_values'], true) : null;
        }
        return $rows;
    }
}
