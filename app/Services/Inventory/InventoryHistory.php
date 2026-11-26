<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\Database;

/**
 * Insert-only history of inventory records (warehouses, locations, settings, documents,
 * batches, serials, reservations, valuation): event, status change, old/new values, reason,
 * who and when. Complements the global activity log; nothing here is ever edited or deleted.
 */
final class InventoryHistory
{
    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new @param array<string, mixed> $actor */
    public static function write(Database $db, string $subjectType, int $subjectId, string $event, ?array $old = null, ?array $new = null, ?string $reason = null,
        array $actor = [], ?int $warehouseId = null, ?string $from = null, ?string $to = null, ?string $date = null, ?int $productId = null): void
    {
        $db->insert('inventory_history', [
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'warehouse_id' => $warehouseId, 'product_id' => $productId, 'event' => $event,
            'from_status' => $from, 'to_status' => $to,
            'old_values' => $old === null || $old === [] ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null || $new === [] ? null : json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null && trim($reason) !== '' ? mb_substr($reason, 0, 500) : null,
            'effective_date' => $date, 'actor_id' => isset($actor['id']) ? (int) $actor['id'] : null, 'created_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> newest first */
    public static function read(Database $db, string $where, array $params, int $limit = 250): array
    {
        $rows = $db->select("SELECT h.*, u.name AS actor_name, w.code AS warehouse_code, w.name AS warehouse_name, p.name AS product_name, p.product_code
            FROM inventory_history h LEFT JOIN users u ON u.id = h.actor_id LEFT JOIN warehouses w ON w.id = h.warehouse_id LEFT JOIN products p ON p.id = h.product_id
            WHERE {$where} ORDER BY h.id DESC LIMIT " . max(1, $limit), $params);
        foreach ($rows as &$r) {
            $r['old_values'] = $r['old_values'] !== null ? json_decode((string) $r['old_values'], true) : null;
            $r['new_values'] = $r['new_values'] !== null ? json_decode((string) $r['new_values'], true) : null;
        }
        return $rows;
    }
}
