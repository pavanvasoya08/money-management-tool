<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Inventory\BatchSerialService;
use App\Services\Inventory\CorrectionService;
use App\Services\Inventory\InventoryHistory;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\ProductInventoryService;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\UnitService;

/**
 * Inventory view of products and variants (existing Phase 6 records, never duplicated): the
 * product list with stock, the 8-tab product inventory page and the product inventory settings.
 */
final class ProductInventoryController extends InventoryBaseController
{
    public const TABS = ['summary' => 'Stock summary', 'warehouses' => 'Warehouse-wise stock', 'ledger' => 'Stock ledger', 'batches' => 'Batches', 'serials' => 'Serial numbers',
        'reservations' => 'Reservations', 'valuation' => 'Valuation', 'history' => 'History'];

    public function index(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status'], ['warehouse', 'category', 'brand']);
        $rows = (new StockQueryService(App::db()))->products($this->user(), $f);
        return $this->view('inventory/products/index', ['title' => 'Inventory products', 'breadcrumbs' => self::CRUMB + ['Products' => null], 'f' => $f,
            'rows' => WarehouseController::pageOf($rows, $this->page($request), $this->perPage() * 2), 'warehouses' => $this->warehouseOptions(),
            'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions(), 'untracked' => (int) App::db()->scalar("SELECT COUNT(*) FROM products p JOIN product_types t ON t.id = p.product_type_id
                WHERE p.tracks_inventory = 0 AND t.is_service = 0 AND p.status <> 'archived'")]);
    }

    public function show(Request $request, string $id): Response
    {
        $pid = $this->intParam($id);
        $db = App::db();
        $me = $this->user();
        $pis = new ProductInventoryService($db);
        try {
            $c = $pis->config($pid);
        } catch (\App\Exceptions\DomainRuleException) {
            throw new HttpException(404, 'Product not found.');
        }
        $tabs = self::TABS;
        if (!can('inventory.view_history')) {
            unset($tabs['history']);
        }
        if (!can('inventory.view_stock_ledger')) {
            unset($tabs['ledger']);
        }
        if (!can('inventory.view_stock_cost')) {
            unset($tabs['valuation']);
        }
        if (!$c['batch_tracking']) {
            unset($tabs['batches']);
        }
        if (!$c['serial_tracking']) {
            unset($tabs['serials']);
        }
        $tab = $request->string('tab', 'summary');
        if (!isset($tabs[$tab])) {
            $tab = 'summary';
        }
        $q = new StockQueryService($db);
        $variant = (int) $request->input('variant', 0) ?: '';
        $items = $q->items($me, ['product' => $pid, 'variant' => $variant]);
        $variants = $db->select("SELECT id, name, sku, status FROM product_variants WHERE product_id = ? AND status <> 'archived' ORDER BY name", [$pid]);
        $totals = ['on_hand' => 0, 'sellable' => 0, 'available' => 0, 'reserved' => 0, 'damaged' => 0, 'quarantine' => 0, 'in_transit' => 0, 'expired' => 0, 'value' => 0];
        foreach ($items as $i) {
            foreach ($totals as $k => $_) {
                $totals[$k] += $i[$k];
            }
        }
        $page = $this->page($request);
        $data = ['title' => $c['product']['name'], 'breadcrumbs' => self::CRUMB + ['Products' => route('inventory.products.index'), $c['product']['product_code'] => null],
            'c' => $c, 'tab' => $tab, 'tabs' => $tabs, 'items' => $items, 'totals' => $totals, 'variants' => $variants, 'variant' => $variant,
            'levels' => $q->levels($pid, null, null), 'productStatus' => (string) $c['product']['status']];
        $data += match ($tab) {
            'summary' => ['rules' => $pis->rules($pid), 'warehouses' => $this->warehouseOptions(), 'units' => $this->unitOptions(), 'conversions' => (new UnitService($db))->conversions($pid),
                'hasStock' => $pis->hasStockOnHand($pid), 'hasMovements' => (bool) $db->scalar('SELECT 1 FROM inventory_stock_movements WHERE product_id = ? LIMIT 1', [$pid]),
                'defaultMethod' => (string) App::settings()->get('inventory_valuation_method', 'weighted_average')],
            'warehouses' => [],
            'ledger' => ['movements' => $q->movements($me, ['product' => $pid, 'variant' => $variant], $this->perPage() * 4, ($page - 1) * $this->perPage() * 4),
                'movementTotal' => $q->movementCount($me, ['product' => $pid, 'variant' => $variant]), 'page' => $page, 'per' => $this->perPage() * 4],
            'batches' => ['batches' => (new BatchSerialService($db))->batches($me, ['product' => $pid])],
            'serials' => ['serials' => (new BatchSerialService($db))->serials($me, ['product' => $pid])],
            'reservations' => ['reservations' => $this->reservationsFor($pid)],
            'valuation' => ['valuationHistory' => $db->select('SELECT h.*, u.name AS created_by_name, w.code AS warehouse_code FROM inventory_valuation_history h LEFT JOIN users u ON u.id = h.created_by
                LEFT JOIN warehouses w ON w.id = h.warehouse_id WHERE h.product_id = ? ORDER BY h.id DESC LIMIT 100', [$pid]), 'layers' => $this->layersFor($pid),
                'corrections' => $this->revaluationsFor($items)],
            'history' => ['history' => $this->historyFor($pid)],
        };
        return $this->view('inventory/products/show', $data);
    }

    public function saveSettings(Request $request, string $id): Response
    {
        $pid = $this->intParam($id);
        (new ProductInventoryService(App::db()))->save($pid, $request->all(), $this->user());
        return $this->back(route('inventory.products.show', ['id' => $pid]), 'Inventory settings saved.');
    }

    public function saveRule(Request $request, string $id): Response
    {
        $pid = $this->intParam($id);
        $this->authorize('inventory.manage_reorder_settings');
        if (($w = (int) $request->input('warehouse_id', 0)) > 0) {
            InventoryScope::assert($this->user(), $w);
        }
        (new ProductInventoryService(App::db()))->saveRule($pid, $request->all(), $this->user());
        return $this->back(route('inventory.products.show', ['id' => $pid]) . '#levels', 'Reorder levels saved.');
    }

    public function endRule(Request $request, string $id, string $rule): Response
    {
        $pid = $this->intParam($id);
        $this->authorize('inventory.manage_reorder_settings');
        (new ProductInventoryService(App::db()))->endRule($pid, $this->intParam($rule), $this->user());
        return $this->back(route('inventory.products.show', ['id' => $pid]) . '#levels', 'Reorder levels removed — the product-wide levels apply again.');
    }

    public function addConversion(Request $request, string $id): Response
    {
        $pid = $this->intParam($id);
        (new UnitService(App::db()))->addConversion(['product_id' => $pid] + $request->all(), $this->user());
        return $this->back(route('inventory.products.show', ['id' => $pid]) . '#units', 'Unit conversion added for this product.');
    }

    public function revalue(Request $request, string $id): Response
    {
        $pid = $this->intParam($id);
        $item = App::db()->first('SELECT * FROM inventory_stock_items WHERE id = ? AND product_id = ?', [(int) $request->input('stock_item_id', 0), $pid]) ?? throw ValidationException::withMessage('stock_item_id', 'Choose the stock to revalue.');
        InventoryScope::assert($this->user(), (int) $item['warehouse_id']);
        $result = (new CorrectionService(App::db()))->requestRevaluation($item, $request->string('unit_cost'), $request->string('reason'), $this->user());
        return $this->back(route('inventory.products.show', ['id' => $pid, 'tab' => 'valuation']), $result === 'applied' ? 'Stock revalued.' : 'Revaluation sent for approval.');
    }

    // ---------------------------------------------------------------- internals

    /** @return list<array<string, mixed>> */
    private function reservationsFor(int $pid): array
    {
        [$w, $p] = InventoryScope::sql($this->user(), 'r.warehouse_id');
        (new ReservationService(App::db()))->expireDue();
        return App::db()->select("SELECT ri.*, r.reservation_no, r.reference_type, r.reference_no, r.reservation_date, r.expires_on, r.status AS header_status, w.code AS warehouse_code, v.name AS variant_name
            FROM inventory_reservation_items ri JOIN inventory_reservations r ON r.id = ri.reservation_id JOIN warehouses w ON w.id = r.warehouse_id LEFT JOIN product_variants v ON v.id = ri.variant_id
            WHERE ri.product_id = ? AND {$w} ORDER BY r.reservation_date DESC, r.id DESC LIMIT 300", array_merge([$pid], $p));
    }

    /** Open FIFO cost layers (only for FIFO-valued products). @return list<array<string, mixed>> */
    private function layersFor(int $pid): array
    {
        if (InventoryLedger::method(App::db(), $pid) !== 'fifo') {
            return [];
        }
        [$w, $p] = InventoryScope::sql($this->user(), 'i.warehouse_id');
        return App::db()->select("SELECT l.*, i.warehouse_id, w.code AS warehouse_code, v.name AS variant_name, m.movement_no FROM inventory_cost_layers l JOIN inventory_stock_items i ON i.id = l.stock_item_id
            JOIN warehouses w ON w.id = i.warehouse_id LEFT JOIN product_variants v ON v.id = i.variant_id LEFT JOIN inventory_stock_movements m ON m.id = l.movement_id
            WHERE i.product_id = ? AND l.qty_remaining > 0 AND {$w} ORDER BY l.id", array_merge([$pid], $p));
    }

    /** @param list<array<string, mixed>> $items @return list<array<string, mixed>> */
    private function revaluationsFor(array $items): array
    {
        $out = [];
        $svc = new CorrectionService(App::db());
        foreach ($items as $i) {
            foreach ($svc->forSubject('stock_item', (int) $i['id']) as $c) {
                $out[] = $c + ['warehouse_code' => $i['warehouse_code'], 'variant_name' => $i['variant_name']];
            }
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function historyFor(int $pid): array
    {
        [$w, $p] = InventoryScope::sql($this->user(), 'h.warehouse_id');
        return InventoryHistory::read(App::db(), "h.product_id = ? AND (h.warehouse_id IS NULL OR {$w})", array_merge([$pid], $p), 300);
    }
}
