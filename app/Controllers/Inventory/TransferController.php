<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Inventory\InventoryDocumentService;
use App\Services\Inventory\InventoryHistory;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\LocationService;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\TransferService;
use App\Services\Inventory\WarehouseService;
use App\Support\BusinessDate;
use App\Support\Qty;

/** Stock transfers between warehouses (in transit, partial receipt, losses) and between locations of one warehouse. */
final class TransferController extends InventoryBaseController
{
    private TransferService $transfers;

    public function __construct()
    {
        $this->transfers = new TransferService(App::db());
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status', 'from', 'to'], ['warehouse', 'product']);
        return $this->view('inventory/transfers/index', ['title' => 'Stock transfers', 'breadcrumbs' => self::CRUMB + ['Stock transfers' => null], 'f' => $f,
            'docs' => $this->transfers->paginate($this->user(), $f, $this->page($request), $this->perPage()), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions()]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null, (int) $request->input('from', 0), (int) $request->input('to', 0));
    }

    public function store(Request $request): Response
    {
        $input = $this->withLines($request->all());
        $this->assertWarehouses($input);
        $id = $this->transfers->save($input, null, $this->user());
        return $this->afterSave($request, $id);
    }

    public function show(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $me = $this->user();
        $db = App::db();
        $items = $this->transfers->items((int) $t['id']);
        $outstanding = 0;
        foreach ($items as &$i) {
            $i['outstanding'] = Qty::fromDb($i['quantity']) - Qty::fromDb($i['qty_received']) - Qty::fromDb($i['qty_damaged']) - Qty::fromDb($i['qty_lost']);
            $outstanding += $i['outstanding'];
        }
        unset($i);
        $seesTo = InventoryScope::allows($me, (int) $t['to_warehouse_id']);
        return $this->view('inventory/transfers/show', ['title' => $t['transfer_no'], 'breadcrumbs' => self::CRUMB + ['Stock transfers' => route('inventory.transfers.index'), $t['transfer_no'] => null],
            't' => $t, 'items' => $items, 'receipts' => $this->transfers->receipts((int) $t['id']), 'outstanding' => $outstanding, 'seesTo' => $seesTo,
            'toLocations' => $seesTo ? (new LocationService($db))->options((int) $t['to_warehouse_id']) : [],
            'documents' => can('inventory.download_documents') || can('inventory.manage_documents') ? (new InventoryDocumentService($db))->list('transfer', (int) $t['id'], $request->string('archived') === '1') : [],
            'categories' => InventoryDocumentService::CATEGORIES,
            'history' => can('inventory.view_history') ? InventoryHistory::read($db, "h.subject_type = 'transfer' AND h.subject_id = ?", [$t['id']]) : [],
            'movements' => can('inventory.view_stock_ledger') ? (new StockQueryService($db))->movements($me, ['source' => 'transfer', 'q' => $t['transfer_no']], 200) : [],
            'today' => BusinessDate::today()]);
    }

    public function edit(Request $request, string $id): Response
    {
        $t = $this->find($id);
        return $this->form($t, (int) $request->input('from', 0) ?: (int) $t['from_warehouse_id'], (int) $request->input('to', 0) ?: (int) $t['to_warehouse_id']);
    }

    public function update(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $input = $this->withLines($request->all());
        $this->assertWarehouses($input);
        $this->transfers->save($input, $t, $this->user());
        return $this->afterSave($request, (int) $t['id']);
    }

    public function submit(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $fresh = $this->transfers->submit($t, $this->user());
        return $this->toShow($fresh, match ($fresh['transfer_status']) {
            'in_transit' => 'Approved and dispatched — the stock is now in transit.',
            'received' => 'Approved and moved.',
            default => 'Sent for approval. It is dispatched once approved.',
        });
    }

    public function dispatch(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $this->transfers->dispatch($t, $this->user());
        $fresh = $this->transfers->find((int) $t['id']);
        return $this->toShow($fresh, $fresh['transfer_status'] === 'in_transit' ? 'Dispatched — the stock is now in transit.' : 'Stock moved.');
    }

    public function receive(Request $request, string $id): Response
    {
        $t = $this->find($id);
        InventoryScope::assert($this->user(), (int) $t['to_warehouse_id']);
        $this->transfers->receive($t, (array) $request->input('lines', []), $request->string('received_date') ?: BusinessDate::today(), $request->string('notes'), $this->user());
        $fresh = $this->transfers->find((int) $t['id']);
        return $this->toShow($fresh, $fresh['transfer_status'] === 'received' ? 'Receipt recorded — the transfer is complete.' : 'Partial receipt recorded. The rest stays in transit.');
    }

    public function loss(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $this->authorize('inventory.manage_stock_adjustments');
        $this->transfers->writeOffLoss($t, (array) $request->input('lines', []), $request->string('reason'), $this->user());
        return $this->toShow($t, 'Loss in transit written off.');
    }

    public function cancel(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $this->transfers->cancel($t, $request->string('reason'), $this->user());
        return $this->toShow($t, 'Transfer cancelled.');
    }

    public function reverse(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $this->authorize('inventory.reverse_stock_movements');
        $this->transfers->reverse($t, $request->string('reason'), $this->user());
        return $this->toShow($t, 'Transfer reversed — the stock is back at the source.');
    }

    public function uploadDoc(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $this->uploadDocument($request, 'transfer', (int) $t['id'], (int) $t['from_warehouse_id']);
        return $this->toShow($t, 'Document uploaded.');
    }

    // ---------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $t = $this->transfers->find($this->intParam($id)) ?? throw new HttpException(404, 'Transfer not found.');
        $me = $this->user();
        if (!InventoryScope::allows($me, (int) $t['from_warehouse_id']) && !InventoryScope::allows($me, (int) $t['to_warehouse_id'])) {
            throw new HttpException(404, 'Transfer not found.');
        }
        return $t;
    }

    /** @param array<string, mixed> $input */
    private function assertWarehouses(array $input): void
    {
        InventoryScope::assert($this->user(), (int) ($input['from_warehouse_id'] ?? 0));
        InventoryScope::assert($this->user(), (int) ($input['to_warehouse_id'] ?? 0));
    }

    /** @param array<string, mixed>|null $t */
    private function form(?array $t, int $from, int $to): Response
    {
        if ($t !== null && !in_array($t['transfer_status'], ['draft', 'rejected'], true)) {
            return $this->toShow($t, 'Only drafts and rejected transfers can be edited.', 'error');
        }
        $ws = new WarehouseService(App::db());
        $me = $this->user();
        $fw = $from > 0 && InventoryScope::allows($me, $from) ? $ws->find($from) : null;
        $tw = $to > 0 && InventoryScope::allows($me, $to) ? $ws->find($to) : null;
        $lines = [];
        if ($t !== null) {
            foreach ($this->transfers->items((int) $t['id']) as $l) {
                $lines[] = ['item' => $l['product_id'] . ':' . ($l['variant_id'] ?? ''), 'quantity' => Qty::toInput(Qty::fromDb($l['quantity'])), 'unit_code' => $l['stock_unit'],
                    'from_location_id' => $l['from_location_id'], 'to_location_id' => $l['to_location_id'], 'batch_no' => $l['batch_no'] ?? '', 'serial_numbers' => $l['serial_numbers'], 'notes' => $l['notes']];
            }
        }
        return $this->view('inventory/transfers/form', [
            'title' => $t === null ? 'New stock transfer' : 'Edit ' . $t['transfer_no'],
            'breadcrumbs' => self::CRUMB + ['Stock transfers' => route('inventory.transfers.index')] + ($t !== null ? [$t['transfer_no'] => route('inventory.transfers.show', ['id' => $t['id']])] : []) + [$t === null ? 'New' : 'Edit' => null],
            't' => $t, 'fw' => $fw, 'tw' => $tw, 'warehouses' => $ws->options($me), 'items' => $this->itemOptions(), 'units' => $this->unitOptions(),
            'fromLocations' => $fw !== null ? $this->locationOptions((int) $fw['id']) : [], 'toLocations' => $tw !== null ? $this->locationOptions((int) $tw['id']) : [],
            'lines' => $this->oldLines($lines), 'today' => BusinessDate::today(), 'inTransit' => (bool) App::settings()->get('inventory_transfer_in_transit', true),
        ]);
    }

    private function afterSave(Request $request, int $id): Response
    {
        if ($request->string('action') === 'submit') {
            return $this->submit($request, (string) $id);
        }
        return $this->toShow($this->transfers->find($id), 'Draft saved. Submit it when it is complete.');
    }

    /** @param array<string, mixed> $t */
    private function toShow(array $t, string $message, string $type = 'success'): Response
    {
        return $this->redirectRoute('inventory.transfers.show', ['id' => $t['id']])->with($type, $message);
    }
}
