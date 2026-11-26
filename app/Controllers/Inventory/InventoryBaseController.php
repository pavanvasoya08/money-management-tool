<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Inventory\InventoryDocumentService;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\LocationService;
use App\Services\Inventory\UnitService;
use App\Services\Inventory\WarehouseService;

/**
 * Shared helpers for inventory pages. Every record is loaded through the warehouse record
 * scope (404 outside it); stock figures, documents and reports only cover visible warehouses.
 */
abstract class InventoryBaseController extends Controller
{
    protected const CRUMB = ['Inventory' => '/inventory'];

    /** @return array<string, mixed> */
    protected function findWarehouse(string|int $id): array
    {
        $wid = is_int($id) ? $id : $this->intParam($id);
        InventoryScope::assert($this->user(), $wid);
        return (new WarehouseService(App::db()))->find($wid) ?? throw new HttpException(404, 'Warehouse not found.');
    }

    /** Warehouses the user may see. @return list<array<string, mixed>> */
    protected function warehouseOptions(bool $includeInactive = true): array
    {
        return (new WarehouseService(App::db()))->options($this->user(), $includeInactive);
    }

    /**
     * Inventory-tracked products and variants (for item pickers). Values are "productId:variantId".
     * @return list<array{value: string, label: string, product_id: int, variant_id: int|null, unit: string, batch: bool, serial: bool, expiry: bool}>
     */
    protected function itemOptions(): array
    {
        $rows = App::db()->select("SELECT p.id, p.name, p.product_code, p.sku, p.unit, v.id AS variant_id, v.name AS variant_name, v.sku AS variant_sku,
                COALESCE(s.track_level, CASE WHEN EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id AND x.status <> 'archived') THEN 'variant' ELSE 'product' END) AS track_level,
                COALESCE(s.batch_tracking, 0) AS batch_tracking, COALESCE(s.serial_tracking, 0) AS serial_tracking, COALESCE(s.expiry_tracking, 0) AS expiry_tracking
            FROM products p JOIN product_types t ON t.id = p.product_type_id LEFT JOIN product_inventory_settings s ON s.product_id = p.id
            LEFT JOIN product_variants v ON v.product_id = p.id AND v.status NOT IN ('archived', 'inactive')
            WHERE p.tracks_inventory = 1 AND t.is_service = 0 AND p.status NOT IN ('archived', 'draft') ORDER BY p.name, v.name LIMIT 3000");
        $out = [];
        foreach ($rows as $r) {
            $variant = $r['track_level'] === 'variant' ? $r['variant_id'] : null;
            if ($r['track_level'] === 'variant' && $variant === null) {
                continue;
            }
            $key = $r['id'] . ':' . ($variant ?? '');
            if (isset($out[$key])) {
                continue;
            }
            $out[$key] = ['value' => $key, 'product_id' => (int) $r['id'], 'variant_id' => $variant !== null ? (int) $variant : null,
                'label' => $r['name'] . ($variant !== null ? ' — ' . $r['variant_name'] : '') . ' (' . ($variant !== null ? ($r['variant_sku'] ?: $r['product_code']) : ($r['sku'] ?: $r['product_code'])) . ')',
                'unit' => (string) $r['unit'], 'batch' => (int) $r['batch_tracking'] === 1, 'serial' => (int) $r['serial_tracking'] === 1, 'expiry' => (int) $r['expiry_tracking'] === 1];
        }
        return array_values($out);
    }

    /** @return list<array<string, mixed>> */
    protected function unitOptions(): array
    {
        return (new UnitService(App::db()))->units(true);
    }

    /** @return list<array<string, mixed>> */
    protected function locationOptions(int $warehouseId): array
    {
        return (new LocationService(App::db()))->options($warehouseId);
    }

    /** Suppliers the user may see (for references). @return list<array<string, mixed>> */
    protected function supplierOptions(): array
    {
        if (!can('suppliers.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'suppliers', 's');
        return App::db()->select("SELECT s.id, s.supplier_code, s.name FROM suppliers s WHERE {$w} AND s.status IN ('active', 'inactive') ORDER BY s.name LIMIT 1000", $p);
    }

    /** @return list<array<string, mixed>> */
    protected function gstOptions(): array
    {
        if (!can('gst_registrations.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'gst_registrations', 'g');
        return App::db()->select("SELECT g.id, g.gstin, g.legal_name, g.trade_name FROM gst_registrations g WHERE {$w} AND g.is_archived = 0 AND g.status <> 'cancelled' ORDER BY g.gstin", $p);
    }

    /** @return list<array<string, mixed>> */
    protected function activityOptions(): array
    {
        return App::db()->select('SELECT id, code, name FROM gst_business_activities WHERE is_active = 1 ORDER BY sort_order, name');
    }

    /** @return list<array<string, mixed>> */
    protected function categoryOptions(): array
    {
        return (new \App\Services\Product\CatalogService(App::db()))->categoryTree();
    }

    /** @return list<array<string, mixed>> */
    protected function brandOptions(): array
    {
        return (new \App\Services\Product\CatalogService(App::db()))->brands();
    }

    /** @param list<string> $text @param list<string> $ints @return array<string, mixed> */
    protected function filters(Request $request, array $text, array $ints): array
    {
        $f = [];
        foreach ($text as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
            if (in_array($k, ['from', 'to', 'as_of'], true) && $f[$k] !== '' && !\App\Core\Validator::isDate($f[$k])) {
                $f[$k] = '';
            }
        }
        foreach ($ints as $k) {
            $f[$k] = max(0, (int) $request->input($k, 0)) ?: '';
        }
        if (($f['warehouse'] ?? '') !== '' && !InventoryScope::allows($this->user(), (int) $f['warehouse'])) {
            $f['warehouse'] = '';
        }
        return $f;
    }

    /**
     * Line items posted by the line editor: "item" (productId:variantId) is split into product_id / variant_id.
     * @param array<string, mixed> $input @return array<string, mixed>
     */
    protected function withLines(array $input): array
    {
        $lines = [];
        $i = 0;
        foreach ((array) ($input['lines'] ?? []) as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            if (isset($raw['item']) && !isset($raw['product_id'])) {
                [$p, $v] = array_pad(explode(':', (string) $raw['item'], 2), 2, '');
                $raw['product_id'] = ctype_digit($p) ? (int) $p : 0;
                $raw['variant_id'] = ctype_digit($v) ? (int) $v : null;
            }
            $lines[++$i] = $raw;
        }
        $input['lines'] = $lines;
        return $input;
    }

    /** Old line input after a failed submit (re-keyed 1..n), or $fallback. @param list<array<string, mixed>> $fallback @return list<array<string, mixed>> */
    protected function oldLines(array $fallback): array
    {
        $old = old('lines', null);
        if (!is_array($old)) {
            return $fallback;
        }
        return array_values(array_filter($old, 'is_array'));
    }

    protected function uploadDocument(Request $request, string $subject, int $id, int $warehouseId): void
    {
        $this->authorize('inventory.manage_documents');
        $file = $request->file('file');
        if ($file === null) {
            throw \App\Exceptions\ValidationException::withMessage('file', 'Choose a file to upload.');
        }
        (new InventoryDocumentService(App::db()))->upload($subject, $id, $warehouseId, $file, $request->all(), $this->user());
    }

    /** Pages size. */
    protected function perPage(): int
    {
        return max(5, (int) setting('records_per_page', 15));
    }

    protected function page(Request $request): int
    {
        return max(1, (int) $request->input('page', 1));
    }

    protected function back(string $url, string $message, string $type = 'success'): Response
    {
        return $this->redirect($url)->with($type, $message);
    }
}
