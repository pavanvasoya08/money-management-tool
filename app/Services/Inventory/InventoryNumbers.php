<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Core\Database;

/** Sequential document numbers per prefix (WH-0001, OPN-000001, …). Unique indexes catch any race. */
final class InventoryNumbers
{
    public const PREFIXES = [
        'opening' => ['inventory_entries', 'entry_no', 'OPN'], 'inward' => ['inventory_entries', 'entry_no', 'INW'], 'outward' => ['inventory_entries', 'entry_no', 'OUT'],
        'transfer' => ['inventory_transfers', 'transfer_no', 'TRF'], 'receipt' => ['inventory_transfer_receipts', 'receipt_no', 'TRC'],
        'adjustment' => ['inventory_adjustments', 'adjustment_no', 'ADJ'], 'reservation' => ['inventory_reservations', 'reservation_no', 'RSV'],
        'warehouse' => ['warehouses', 'code', 'WH'],
    ];

    public static function next(Database $db, string $kind): string
    {
        [$table, $col, $prefix] = self::PREFIXES[$kind];
        $width = $kind === 'warehouse' ? 3 : 6;
        $max = (int) $db->scalar("SELECT COALESCE(MAX(CAST(SUBSTR({$col}, " . (strlen($prefix) + 2) . ") AS UNSIGNED)), 0) FROM {$table} WHERE {$col} LIKE ?", [$prefix . '-%']);
        return $prefix . '-' . str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);
    }
}
