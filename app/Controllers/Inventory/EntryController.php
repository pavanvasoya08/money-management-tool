<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Inventory\EntryService;
use App\Services\Inventory\InventoryDocumentService;
use App\Services\Inventory\InventoryHistory;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\WarehouseService;
use App\Support\BusinessDate;
use App\Support\Qty;

/**
 * Opening stock, stock inward and stock outward documents: list, draft, submit for approval,
 * posting to the ledger (on approval), cancellation and reversal. The direction comes from the route.
 */
final class EntryController extends InventoryBaseController
{
    private const LABELS = ['opening' => 'Opening stock', 'inward' => 'Stock inward', 'outward' => 'Stock outward'];

    private EntryService $entries;

    public function __construct()
    {
        $this->entries = new EntryService(App::db());
    }

    public function index(Request $request): Response
    {
        $dir = $this->direction($request);
        $f = $this->filters($request, ['q', 'status', 'type', 'from', 'to'], ['warehouse', 'supplier', 'product']);
        return $this->view('inventory/entries/index', ['title' => self::LABELS[$dir], 'breadcrumbs' => self::CRUMB + [self::LABELS[$dir] => null], 'dir' => $dir, 'f' => $f,
            'docs' => $this->entries->paginate($this->user(), $dir, $f, $this->page($request), $this->perPage()), 'warehouses' => $this->warehouseOptions(),
            'suppliers' => $dir === 'opening' ? [] : $this->supplierOptions(), 'items' => $this->itemOptions(), 'canCreate' => can('inventory.' . EntryService::PERMISSIONS[$dir])]);
    }

    public function create(Request $request): Response
    {
        $dir = $this->direction($request);
        $this->authorize('inventory.' . EntryService::PERMISSIONS[$dir]);
        return $this->form($dir, null, (int) $request->input('warehouse', 0));
    }

    public function store(Request $request): Response
    {
        $dir = $this->direction($request);
        $this->authorize('inventory.' . EntryService::PERMISSIONS[$dir]);
        $input = $this->withLines($request->all());
        InventoryScope::assert($this->user(), (int) ($input['warehouse_id'] ?? 0));
        $id = $this->entries->save($dir, $input, null, $this->user());
        return $this->afterSave($request, $id);
    }

    public function show(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $db = App::db();
        $docs = can('inventory.download_documents') || can('inventory.manage_documents') ? (new InventoryDocumentService($db))->list('entry', (int) $e['id'], $request->string('archived') === '1') : [];
        return $this->view('inventory/entries/show', ['title' => $e['entry_no'], 'breadcrumbs' => self::CRUMB + [self::LABELS[$e['direction']] => route('inventory.' . $e['direction'] . '.index'), $e['entry_no'] => null],
            'e' => $e, 'lines' => $this->entries->lines((int) $e['id']), 'documents' => $docs, 'categories' => InventoryDocumentService::CATEGORIES,
            'history' => can('inventory.view_history') ? InventoryHistory::read($db, "h.subject_type = 'entry' AND h.subject_id = ?", [$e['id']]) : [],
            'movements' => can('inventory.view_stock_ledger') ? (new StockQueryService($db))->movements($this->user(), ['source' => $e['direction'], 'q' => $e['entry_no']], 200) : [],
            'canManage' => can('inventory.' . EntryService::PERMISSIONS[$e['direction']])]);
    }

    public function edit(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $this->authorize('inventory.' . EntryService::PERMISSIONS[$e['direction']]);
        return $this->form((string) $e['direction'], $e, (int) $request->input('warehouse', 0) ?: (int) $e['warehouse_id']);
    }

    public function update(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $this->authorize('inventory.' . EntryService::PERMISSIONS[$e['direction']]);
        $input = $this->withLines($request->all());
        InventoryScope::assert($this->user(), (int) ($input['warehouse_id'] ?? 0));
        $this->entries->save((string) $e['direction'], $input, $e, $this->user());
        return $this->afterSave($request, (int) $e['id']);
    }

    public function submit(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $this->authorize('inventory.' . EntryService::PERMISSIONS[$e['direction']]);
        $fresh = $this->entries->submit($e, $this->user());
        return $this->toShow($fresh, $fresh['status'] === 'posted' ? 'Posted to the stock ledger.' : 'Sent for approval. It is posted to the stock ledger once approved.');
    }

    public function post(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $this->authorize('inventory.' . EntryService::PERMISSIONS[$e['direction']]);
        $this->entries->post($e, $this->user());
        return $this->toShow($e, 'Posted to the stock ledger.');
    }

    public function cancel(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $this->authorize('inventory.' . EntryService::PERMISSIONS[$e['direction']]);
        $this->entries->cancel($e, $request->string('reason'), $this->user());
        return $this->toShow($e, 'Document cancelled.');
    }

    public function reverse(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $this->authorize('inventory.reverse_stock_movements');
        $this->entries->reverse($e, $request->string('reason'), $this->user());
        return $this->toShow($e, 'Reversed. Opposite movements were posted to the stock ledger; the original stays in the history.');
    }

    public function uploadDoc(Request $request, string $id): Response
    {
        $e = $this->find($id);
        $this->uploadDocument($request, 'entry', (int) $e['id'], (int) $e['warehouse_id']);
        return $this->toShow($e, 'Document uploaded.');
    }

    // ---------------------------------------------------------------- internals

    private function direction(Request $request): string
    {
        $route = (string) ($request->attributes['route'] ?? '');
        foreach (array_keys(self::LABELS) as $d) {
            if (str_starts_with($route, "inventory.{$d}.")) {
                return $d;
            }
        }
        throw new HttpException(404, 'Page not found.');
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $e = $this->entries->find($this->intParam($id)) ?? throw new HttpException(404, 'Document not found.');
        InventoryScope::assert($this->user(), (int) $e['warehouse_id']);
        return $e;
    }

    /** @param array<string, mixed>|null $e */
    private function form(string $dir, ?array $e, int $warehouseId): Response
    {
        if ($e !== null && !in_array($e['status'], ['draft', 'rejected'], true)) {
            return $this->toShow($e, 'Only drafts and rejected documents can be edited.', 'error');
        }
        $options = (new WarehouseService(App::db()))->options($this->user());
        $w = null;
        if ($warehouseId > 0 && InventoryScope::allows($this->user(), $warehouseId)) {
            $w = (new WarehouseService(App::db()))->find($warehouseId);
        }
        if ($w === null && count($options) === 1) {
            $w = (new WarehouseService(App::db()))->find((int) $options[0]['id']);
        }
        $lines = $e !== null ? $this->storedLines($e) : [];
        $label = self::LABELS[$dir];
        return $this->view('inventory/entries/form', [
            'title' => $e === null ? 'New ' . strtolower($label) : 'Edit ' . $e['entry_no'],
            'breadcrumbs' => self::CRUMB + [$label => route("inventory.{$dir}.index")] + ($e !== null ? [$e['entry_no'] => route("inventory.{$dir}.show", ['id' => $e['id']])] : []) + [$e === null ? 'New' : 'Edit' => null],
            'dir' => $dir, 'e' => $e, 'w' => $w, 'warehouses' => $options, 'types' => EntryService::TYPES[$dir], 'items' => $this->itemOptions(), 'units' => $this->unitOptions(),
            'locations' => $w !== null ? $this->locationOptions((int) $w['id']) : [], 'suppliers' => $dir === 'opening' ? [] : $this->supplierOptions(),
            'batches' => $w !== null ? $this->batchList((int) $w['id']) : [], 'reservations' => $dir === 'outward' && $w !== null ? $this->reservationLines((int) $w['id']) : [],
            'lines' => $this->oldLines($lines), 'today' => BusinessDate::today(), 'canOverride' => can('inventory.override_stock_rules'),
        ]);
    }

    /** @param array<string, mixed> $e @return list<array<string, mixed>> */
    private function storedLines(array $e): array
    {
        $out = [];
        foreach ($this->entries->lines((int) $e['id']) as $l) {
            $out[] = ['item' => $l['product_id'] . ':' . ($l['variant_id'] ?? ''), 'quantity' => Qty::toInput(Qty::fromDb($l['base_quantity'])), 'unit_code' => $l['stock_unit'],
                'unit_cost' => $l['unit_cost'] !== null ? Qty::toInput(Qty::fromDb($l['unit_cost'])) : '', 'location_id' => $l['location_id'], 'batch_no' => $l['batch_label'] ?? $l['batch_no'] ?? '',
                'mfg_date' => $l['mfg_date'], 'expiry_date' => $l['expiry_date'], 'serial_numbers' => $l['serial_numbers'], 'return_condition' => $l['return_condition'], 'stock_state' => $l['stock_state'],
                'reservation_item_id' => $l['reservation_item_id'], 'notes' => $l['notes']];
        }
        return $out;
    }

    /** Batch numbers with sellable stock in the warehouse (for the batch field suggestions). @return list<array<string, mixed>> */
    private function batchList(int $warehouseId): array
    {
        return App::db()->select("SELECT b.batch_no, b.expiry_date, p.name AS product_name, SUM(s.quantity) AS qty FROM inventory_stock_balances s JOIN inventory_batches b ON b.id = s.batch_id
            JOIN products p ON p.id = b.product_id WHERE s.warehouse_id = ? AND s.quantity > 0 GROUP BY b.batch_no, b.expiry_date, p.name ORDER BY p.name, b.expiry_date LIMIT 500", [$warehouseId]);
    }

    /** @return list<array<string, mixed>> */
    private function reservationLines(int $warehouseId): array
    {
        return App::db()->select("SELECT i.id, r.reservation_no, r.reference_type, r.reference_no, p.name AS product_name, v.name AS variant_name, i.quantity, i.qty_fulfilled, i.qty_released
            FROM inventory_reservation_items i JOIN inventory_reservations r ON r.id = i.reservation_id JOIN products p ON p.id = i.product_id LEFT JOIN product_variants v ON v.id = i.variant_id
            WHERE r.warehouse_id = ? AND i.status IN ('active', 'partially_fulfilled') ORDER BY r.id DESC LIMIT 300", [$warehouseId]);
    }

    private function afterSave(Request $request, int $id): Response
    {
        $e = $this->entries->find($id);
        if ($request->string('action') === 'submit') {
            $fresh = $this->entries->submit($e, $this->user());
            return $this->toShow($fresh, $fresh['status'] === 'posted' ? 'Saved and posted to the stock ledger.' : 'Saved and sent for approval. It is posted to the stock ledger once approved.');
        }
        return $this->toShow($e, 'Draft saved. Submit it when it is complete.');
    }

    /** @param array<string, mixed> $e */
    private function toShow(array $e, string $message, string $type = 'success'): Response
    {
        return $this->redirectRoute('inventory.' . $e['direction'] . '.show', ['id' => $e['id']])->with($type, $message);
    }

}
