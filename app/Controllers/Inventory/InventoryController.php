<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Export\TableExport;
use App\Services\Inventory\InventoryDashboardService;
use App\Services\Inventory\InventoryDocumentService;
use App\Services\Inventory\InventoryHistory;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\InventoryReportService;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\StockQueryService;
use App\Support\BusinessDate;

/** Inventory dashboard, warehouse stock, stock ledger, valuation, low stock, history, reports and document downloads. */
final class InventoryController extends InventoryBaseController
{
    private const REPORT_TEXT = ['q', 'status', 'type', 'from', 'to', 'serial'];
    private const REPORT_INTS = ['warehouse', 'product', 'variant', 'category', 'brand', 'location', 'batch', 'gst', 'activity'];

    public function dashboard(Request $request): Response
    {
        $f = $this->filters($request, ['status', 'from', 'to'], ['warehouse', 'product', 'variant', 'category', 'brand', 'batch', 'gst', 'activity']);
        return $this->view('inventory/dashboard', ['title' => 'Inventory', 'breadcrumbs' => ['Inventory' => null], 'f' => $f,
            'd' => (new InventoryDashboardService(App::db()))->data($this->user(), $f), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions(),
            'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(),
            'batches' => App::db()->select('SELECT b.id, b.batch_no, p.name AS product_name FROM inventory_batches b JOIN products p ON p.id = b.product_id ORDER BY b.id DESC LIMIT 300'),
            'scopeLabel' => App::dataAccess()->resolve($this->user(), 'inventory')->describe()]);
    }

    public function stock(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status', 'all'], ['warehouse', 'category', 'brand', 'gst', 'activity']);
        $rows = (new StockQueryService(App::db()))->items($this->user(), $f + ['nonzero' => $f['all'] === '1' ? '' : '1']);
        return $this->view('inventory/stock', ['title' => 'Warehouse stock', 'breadcrumbs' => self::CRUMB + ['Warehouse stock' => null], 'f' => $f,
            'rows' => WarehouseController::pageOf($rows, $this->page($request), $this->perPage() * 2), 'all' => $rows, 'warehouses' => $this->warehouseOptions(),
            'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions()]);
    }

    public function ledger(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'type', 'state', 'from', 'to', 'serial'], ['warehouse', 'product', 'variant', 'location', 'batch', 'category', 'brand']);
        $q = new StockQueryService(App::db());
        $per = $this->perPage() * 3;
        $page = $this->page($request);
        $total = $q->movementCount($this->user(), $f);
        return $this->view('inventory/ledger', ['title' => 'Stock ledger', 'breadcrumbs' => self::CRUMB + ['Stock ledger' => null], 'f' => $f,
            'rows' => new Paginator($q->movements($this->user(), $f, $per, ($page - 1) * $per), $total, $page, $per), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions(),
            'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions(),
            'locations' => ($f['warehouse'] ?? '') !== '' ? $this->locationOptions((int) $f['warehouse']) : []]);
    }

    public function valuation(Request $request): Response
    {
        $f = $this->filters($request, ['q'], ['warehouse', 'category', 'brand']);
        $result = (new InventoryReportService(App::db()))->run($this->user(), 'valuation', $f);
        $per = $this->perPage() * 2;
        $page = $this->page($request);
        $db = App::db();
        [$w, $p] = InventoryScope::sql($this->user(), 'h.warehouse_id');
        return $this->view('inventory/valuation', ['title' => 'Inventory valuation', 'breadcrumbs' => self::CRUMB + ['Valuation' => null], 'f' => $f, 'result' => $result,
            'rows' => new Paginator(array_slice($result['rows'], ($page - 1) * $per, $per), count($result['rows']), $page, $per), 'warehouses' => $this->warehouseOptions(),
            'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions(), 'method' => (string) App::settings()->get('inventory_valuation_method', 'weighted_average'),
            'methods' => InventoryLedger::METHODS,
            'own' => $db->select("SELECT s.valuation_method, COUNT(*) AS n FROM product_inventory_settings s WHERE s.valuation_method IS NOT NULL GROUP BY s.valuation_method"),
            'history' => $db->select("SELECT h.*, u.name AS created_by_name, p.name AS product_name, w.code AS warehouse_code FROM inventory_valuation_history h LEFT JOIN users u ON u.id = h.created_by
                LEFT JOIN products p ON p.id = h.product_id LEFT JOIN warehouses w ON w.id = h.warehouse_id WHERE (h.warehouse_id IS NULL OR {$w}) ORDER BY h.id DESC LIMIT 50", $p),
            'pending' => $db->select("SELECT c.*, u.name AS requested_by_name FROM inventory_change_requests c LEFT JOIN users u ON u.id = c.created_by
                WHERE c.change_type = 'valuation_correction' AND c.status = 'pending_approval' ORDER BY c.id DESC LIMIT 50"),
            'reconcile' => $request->string('checked') === '1' ? App::session()->get('inventory_reconcile') : null]);
    }

    public function reconcile(Request $request): Response
    {
        $this->authorize('inventory.manage_stock_valuation');
        $repair = $request->string('repair') === '1' && App::gate()->isSuperAdmin($this->user());
        $result = (new InventoryLedger(App::db()))->reconcile($repair);
        App::activity()->log('inventory.reconciled', ($repair ? 'Rebuilt' : 'Checked') . ' stock summaries against the stock ledger (' . count($result) . ' differences)',
            module: 'inventory', newValues: ['repair' => $repair]);
        App::session()->put('inventory_reconcile', $result);
        return $this->redirect(route('inventory.valuation', ['checked' => 1]))->with('success', $repair ? 'Summaries rebuilt from the ledger.' : 'Reconciliation finished.');
    }

    public function lowStock(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status'], ['warehouse', 'category', 'brand']);
        $result = (new InventoryReportService(App::db()))->run($this->user(), 'low', $f);
        $rows = (new StockQueryService(App::db()))->reorderSuggestions($this->user(), $f);
        $rows = array_values(array_filter($rows, static fn (array $i): bool => $i['reorder_level'] !== null || $i['min_qty'] !== null || $i['safety_stock'] !== null || $i['stock_status'] === 'negative'));
        if (($f['status'] ?? '') !== '') {
            $rows = array_values(array_filter($rows, static fn (array $i): bool => $i['stock_status'] === $f['status']));
        }
        $out = (new StockQueryService(App::db()))->products($this->user(), ['status' => 'out'] + array_diff_key($f, ['status' => 1]));
        return $this->view('inventory/low-stock', ['title' => 'Low stock & reorder', 'breadcrumbs' => self::CRUMB + ['Low stock' => null], 'f' => $f,
            'rows' => WarehouseController::pageOf($rows, $this->page($request), $this->perPage() * 2), 'outCount' => count($out), 'unset' => (int) App::db()->scalar(
                "SELECT COUNT(*) FROM products p JOIN product_types t ON t.id = p.product_type_id LEFT JOIN product_inventory_settings s ON s.product_id = p.id
                 WHERE p.tracks_inventory = 1 AND t.is_service = 0 AND p.status <> 'archived' AND s.reorder_level IS NULL AND s.min_qty IS NULL AND s.safety_stock IS NULL
                 AND NOT EXISTS (SELECT 1 FROM inventory_reorder_rules r WHERE r.product_id = p.id AND r.is_active = 1)"),
            'warehouses' => $this->warehouseOptions(), 'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions(), 'notice' => $result['notice']]);
    }

    public function history(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'subject', 'from', 'to'], ['warehouse']);
        [$w, $p] = InventoryScope::sql($this->user(), 'h.warehouse_id');
        $where = "(h.warehouse_id IS NULL OR {$w})";
        if (($f['warehouse'] ?? '') !== '') {
            $where .= ' AND h.warehouse_id = ?';
            $p[] = (int) $f['warehouse'];
        }
        if (($f['subject'] ?? '') !== '') {
            $where .= ' AND h.subject_type = ?';
            $p[] = $f['subject'];
        }
        if (($f['from'] ?? '') !== '') {
            $where .= ' AND h.created_at >= ?';
            $p[] = $f['from'] . ' 00:00:00';
        }
        if (($f['to'] ?? '') !== '') {
            $where .= ' AND h.created_at <= ?';
            $p[] = $f['to'] . ' 23:59:59';
        }
        if (($q = trim((string) $f['q'])) !== '') {
            $where .= ' AND (h.event LIKE ? OR h.reason LIKE ? OR h.new_values LIKE ?)';
            array_push($p, "%{$q}%", "%{$q}%", "%{$q}%");
        }
        $rows = InventoryHistory::read(App::db(), $where, $p, 1000);
        return $this->view('inventory/history', ['title' => 'Inventory history', 'breadcrumbs' => self::CRUMB + ['History' => null], 'f' => $f,
            'rows' => WarehouseController::pageOf($rows, $this->page($request), $this->perPage() * 3), 'warehouses' => $this->warehouseOptions(),
            'subjects' => App::db()->select('SELECT DISTINCT subject_type FROM inventory_history ORDER BY subject_type')]);
    }

    public function reports(Request $request): Response
    {
        return $this->view('inventory/reports/index', ['title' => 'Inventory reports', 'breadcrumbs' => self::CRUMB + ['Reports' => null], 'reports' => InventoryReportService::REPORTS]);
    }

    public function report(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::REPORT_TEXT, self::REPORT_INTS);
        $result = (new InventoryReportService(App::db()))->run($this->user(), $report, $f);
        $per = $this->perPage() * 2;
        $page = $this->page($request);
        return $this->view('inventory/reports/show', [
            'title' => $result['title'], 'breadcrumbs' => self::CRUMB + ['Reports' => route('inventory.reports'), $result['title'] => null], 'key' => $report, 'result' => $result, 'f' => $f,
            'rows' => new Paginator(array_slice($result['rows'], ($page - 1) * $per, $per), count($result['rows']), $page, $per), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions(),
            'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(),
            'locations' => ($f['warehouse'] ?? '') !== '' ? $this->locationOptions((int) $f['warehouse']) : [],
            'batches' => App::db()->select('SELECT b.id, b.batch_no, p.name AS product_name FROM inventory_batches b JOIN products p ON p.id = b.product_id ORDER BY b.id DESC LIMIT 300'),
            'canExport' => can('inventory.export'), 'dated' => in_array($report, InventoryReportService::DATED, true),
        ]);
    }

    public function exportReport(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::REPORT_TEXT, self::REPORT_INTS);
        $format = $request->string('format', 'csv');
        $result = (new InventoryReportService(App::db()))->run($this->user(), $report, $f);
        App::activity()->log('inventory.report_exported', "Exported inventory report \"{$result['title']}\" as {$format} (" . count($result['rows']) . ' rows)', module: 'inventory',
            newValues: ['report' => $report, 'format' => $format, 'filters' => array_filter($f), 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'inventory-' . $report);
    }

    public function document(Request $request, string $doc): Response
    {
        $svc = new InventoryDocumentService(App::db());
        $d = $this->scopedDocument($svc, $doc);
        $this->authorize('inventory.download_documents');
        return $svc->response($d, $request->string('inline') === '1');
    }

    public function archiveDocument(Request $request, string $doc): Response
    {
        $svc = new InventoryDocumentService(App::db());
        $d = $this->scopedDocument($svc, $doc);
        $this->authorize('inventory.manage_documents');
        $svc->archive($d, (int) $svc->warehouseOf((string) $d['subject_type'], (int) $d['subject_id']), $request->string('reason'), $this->user());
        return $this->back($request->string('back') !== '' && str_starts_with($request->string('back'), '/') && !str_starts_with($request->string('back'), '//') ? $request->string('back') : route('inventory.dashboard'), 'Document archived.');
    }

    /** @return array<string, mixed> */
    private function scopedDocument(InventoryDocumentService $svc, string $doc): array
    {
        $d = $svc->find($this->intParam($doc)) ?? throw new HttpException(404, 'Document not found.');
        $me = $this->user();
        $wid = $svc->warehouseOf((string) $d['subject_type'], (int) $d['subject_id']);
        $ok = $wid !== null && InventoryScope::allows($me, $wid);
        if (!$ok && $d['subject_type'] === 'transfer') {
            $to = (int) App::db()->scalar('SELECT to_warehouse_id FROM inventory_transfers WHERE id = ?', [$d['subject_id']]);
            $ok = InventoryScope::allows($me, $to);
        }
        if (!$ok) {
            throw new HttpException(404, 'Document not found.');
        }
        return $d;
    }

    /** Today, for views. */
    public static function today(): string
    {
        return BusinessDate::today();
    }
}
