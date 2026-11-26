<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\InventoryDocumentService;
use App\Services\Inventory\InventoryHistory;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\WarehouseService;
use App\Support\BusinessDate;
use App\Support\Qty;

/** Stock adjustments: physical count differences and condition changes, with approval, posting and reversal. */
final class AdjustmentController extends InventoryBaseController
{
    private AdjustmentService $adjustments;

    public function __construct()
    {
        $this->adjustments = new AdjustmentService(App::db());
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status', 'reason', 'from', 'to'], ['warehouse', 'product']);
        return $this->view('inventory/adjustments/index', ['title' => 'Stock adjustments', 'breadcrumbs' => self::CRUMB + ['Stock adjustments' => null], 'f' => $f,
            'docs' => $this->adjustments->paginate($this->user(), $f, $this->page($request), $this->perPage()), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions()]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, (int) $request->input('warehouse', 0));
    }

    public function store(Request $request): Response
    {
        $input = $this->withLines($request->all());
        InventoryScope::assert($this->user(), (int) ($input['warehouse_id'] ?? 0));
        $id = $this->adjustments->save($input, null, $this->user());
        return $this->afterSave($request, $id);
    }

    public function show(Request $request, string $id): Response
    {
        $a = $this->find($id);
        $db = App::db();
        return $this->view('inventory/adjustments/show', ['title' => $a['adjustment_no'], 'breadcrumbs' => self::CRUMB + ['Stock adjustments' => route('inventory.adjustments.index'), $a['adjustment_no'] => null],
            'a' => $a, 'lines' => $this->adjustments->lines((int) $a['id']),
            'documents' => can('inventory.download_documents') || can('inventory.manage_documents') ? (new InventoryDocumentService($db))->list('adjustment', (int) $a['id'], $request->string('archived') === '1') : [],
            'categories' => InventoryDocumentService::CATEGORIES,
            'history' => can('inventory.view_history') ? InventoryHistory::read($db, "h.subject_type = 'adjustment' AND h.subject_id = ?", [$a['id']]) : [],
            'movements' => can('inventory.view_stock_ledger') ? (new StockQueryService($db))->movements($this->user(), ['source' => 'adjustment', 'q' => $a['adjustment_no']], 200) : []]);
    }

    public function edit(Request $request, string $id): Response
    {
        $a = $this->find($id);
        return $this->form($a, (int) $request->input('warehouse', 0) ?: (int) $a['warehouse_id']);
    }

    public function update(Request $request, string $id): Response
    {
        $a = $this->find($id);
        $input = $this->withLines($request->all());
        InventoryScope::assert($this->user(), (int) ($input['warehouse_id'] ?? 0));
        $this->adjustments->save($input, $a, $this->user());
        return $this->afterSave($request, (int) $a['id']);
    }

    public function submit(Request $request, string $id): Response
    {
        $a = $this->find($id);
        $fresh = $this->adjustments->submit($a, $this->user());
        return $this->toShow($fresh, $fresh['status'] === 'posted' ? 'Posted to the stock ledger.' : 'Sent for approval. It is posted once approved.');
    }

    public function post(Request $request, string $id): Response
    {
        $a = $this->find($id);
        $this->adjustments->post($a, $this->user());
        return $this->toShow($a, 'Posted to the stock ledger.');
    }

    public function cancel(Request $request, string $id): Response
    {
        $a = $this->find($id);
        $this->adjustments->cancel($a, $request->string('reason'), $this->user());
        return $this->toShow($a, 'Adjustment cancelled.');
    }

    public function reverse(Request $request, string $id): Response
    {
        $a = $this->find($id);
        $this->authorize('inventory.reverse_stock_movements');
        $this->adjustments->reverse($a, $request->string('reason'), $this->user());
        return $this->toShow($a, 'Adjustment reversed with opposite movements.');
    }

    public function uploadDoc(Request $request, string $id): Response
    {
        $a = $this->find($id);
        $this->uploadDocument($request, 'adjustment', (int) $a['id'], (int) $a['warehouse_id']);
        return $this->toShow($a, 'Document uploaded.');
    }

    // ---------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $a = $this->adjustments->find($this->intParam($id)) ?? throw new HttpException(404, 'Adjustment not found.');
        InventoryScope::assert($this->user(), (int) $a['warehouse_id']);
        return $a;
    }

    /** @param array<string, mixed>|null $a */
    private function form(?array $a, int $warehouseId): Response
    {
        if ($a !== null && !in_array($a['status'], ['draft', 'rejected'], true)) {
            return $this->toShow($a, 'Only drafts and rejected adjustments can be edited.', 'error');
        }
        $ws = new WarehouseService(App::db());
        $options = $ws->options($this->user());
        $w = $warehouseId > 0 && InventoryScope::allows($this->user(), $warehouseId) ? $ws->find($warehouseId) : (count($options) === 1 ? $ws->find((int) $options[0]['id']) : null);
        $lines = [];
        if ($a !== null) {
            foreach ($this->adjustments->lines((int) $a['id']) as $l) {
                $lines[] = ['item' => $l['product_id'] . ':' . ($l['variant_id'] ?? ''), 'line_type' => $l['line_type'], 'stock_state' => $l['stock_state'], 'to_state' => $l['to_state'],
                    'physical_qty' => $l['physical_qty'] !== null ? Qty::toInput(Qty::fromDb($l['physical_qty'])) : '', 'quantity' => Qty::toInput(abs(Qty::fromDb($l['difference_qty']))),
                    'unit_code' => $l['stock_unit'], 'location_id' => $l['location_id'], 'batch_no' => $l['batch_no'] ?? '',
                    'unit_cost' => $l['unit_cost'] !== null ? Qty::toInput(Qty::fromDb($l['unit_cost'])) : '', 'serial_numbers' => $l['serial_numbers'], 'notes' => $l['notes']];
            }
        }
        return $this->view('inventory/adjustments/form', [
            'title' => $a === null ? 'New stock adjustment' : 'Edit ' . $a['adjustment_no'],
            'breadcrumbs' => self::CRUMB + ['Stock adjustments' => route('inventory.adjustments.index')] + ($a !== null ? [$a['adjustment_no'] => route('inventory.adjustments.show', ['id' => $a['id']])] : []) + [$a === null ? 'New' : 'Edit' => null],
            'a' => $a, 'w' => $w, 'warehouses' => $options, 'items' => $this->itemOptions(), 'units' => $this->unitOptions(),
            'locations' => $w !== null ? $this->locationOptions((int) $w['id']) : [], 'lines' => $this->oldLines($lines), 'today' => BusinessDate::today(),
            'stock' => $w !== null ? (new StockQueryService(App::db()))->items($this->user(), ['warehouse' => (int) $w['id'], 'nonzero' => '1']) : [],
        ]);
    }

    private function afterSave(Request $request, int $id): Response
    {
        if ($request->string('action') === 'submit') {
            return $this->submit($request, (string) $id);
        }
        return $this->toShow($this->adjustments->find($id), 'Draft saved. System quantities are checked again when it is posted.');
    }

    /** @param array<string, mixed> $a */
    private function toShow(array $a, string $message, string $type = 'success'): Response
    {
        return $this->redirectRoute('inventory.adjustments.show', ['id' => $a['id']])->with($type, $message);
    }
}
