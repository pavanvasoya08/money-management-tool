<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Inventory\BatchSerialService;
use App\Services\Inventory\CorrectionService;
use App\Services\Inventory\InventoryScope;

/** Batch management (expiry, hold, corrections) and serial number management (history, corrections). */
final class BatchSerialController extends InventoryBaseController
{
    private BatchSerialService $svc;

    public function __construct()
    {
        $this->svc = new BatchSerialService(App::db());
    }

    public function batches(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status', 'expiry', 'stock'], ['warehouse', 'product']);
        if ($request->input('stock') === null) {
            $f['stock'] = 'in_stock';
        }
        $rows = $this->svc->batches($this->user(), $f);
        return $this->view('inventory/batches/index', ['title' => 'Batches & expiry', 'breadcrumbs' => self::CRUMB + ['Batches' => null], 'f' => $f,
            'rows' => WarehouseController::pageOf($rows, $this->page($request), $this->perPage() * 2), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions(),
            'warningDays' => BatchSerialService::warningDays()]);
    }

    public function batch(Request $request, string $id): Response
    {
        $b = $this->findBatch($id);
        return $this->view('inventory/batches/show', ['title' => 'Batch ' . $b['batch_no'], 'breadcrumbs' => self::CRUMB + ['Batches' => route('inventory.batches'), $b['batch_no'] => null],
            'b' => $b, 'stock' => $this->svc->batchStock($this->user(), (int) $b['id']), 'movements' => can('inventory.view_stock_ledger') ? $this->svc->batchMovements($this->user(), (int) $b['id']) : [],
            'changes' => (new CorrectionService(App::db()))->forSubject('batch', (int) $b['id']), 'suppliers' => $this->supplierOptions(),
            'hasMovements' => (bool) App::db()->scalar('SELECT 1 FROM inventory_stock_movements WHERE batch_id = ? LIMIT 1', [$b['id']])]);
    }

    public function updateBatch(Request $request, string $id): Response
    {
        $b = $this->findBatch($id);
        $this->authorize('inventory.manage_batches');
        $this->svc->updateBatch($b, $request->all(), $this->user());
        return $this->back(route('inventory.batches.show', ['id' => $b['id']]), 'Batch saved.');
    }

    public function correctBatch(Request $request, string $id): Response
    {
        $b = $this->findBatch($id);
        $this->authorize('inventory.manage_batches');
        $result = (new CorrectionService(App::db()))->requestBatchCorrection($b, $request->all(), $request->string('reason'), $this->user());
        return $this->back(route('inventory.batches.show', ['id' => $b['id']]), $result === 'applied' ? 'Batch corrected.' : 'Correction sent for approval. The batch keeps its current details until it is approved.');
    }

    public function serials(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status'], ['warehouse', 'product']);
        $rows = $this->svc->serials($this->user(), $f);
        return $this->view('inventory/serials/index', ['title' => 'Serial numbers', 'breadcrumbs' => self::CRUMB + ['Serial numbers' => null], 'f' => $f,
            'rows' => WarehouseController::pageOf($rows, $this->page($request), $this->perPage() * 2), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions()]);
    }

    public function serial(Request $request, string $id): Response
    {
        $s = $this->findSerial($id);
        return $this->view('inventory/serials/show', ['title' => 'Serial ' . $s['serial_no'], 'breadcrumbs' => self::CRUMB + ['Serial numbers' => route('inventory.serials'), $s['serial_no'] => null],
            's' => $s, 'history' => $this->svc->serialHistory((int) $s['id']), 'changes' => (new CorrectionService(App::db()))->forSubject('serial', (int) $s['id'])]);
    }

    public function correctSerial(Request $request, string $id): Response
    {
        $s = $this->findSerial($id);
        $this->authorize('inventory.manage_serial_numbers');
        (new CorrectionService(App::db()))->requestSerialCorrection($s, $request->string('serial_no'), $request->string('reason'), $this->user());
        return $this->back(route('inventory.serials.show', ['id' => $s['id']]), 'Correction sent for approval.');
    }

    /** @return array<string, mixed> */
    private function findBatch(string $id): array
    {
        $b = $this->svc->batch($this->intParam($id)) ?? throw new HttpException(404, 'Batch not found.');
        // A batch is visible when it has (or had) stock in one of the user's warehouses.
        $ids = InventoryScope::warehouseIds($this->user());
        if ($ids !== null) {
            $in = $ids === [] ? '0' : implode(',', array_map('intval', $ids));
            $seen = App::db()->scalar("SELECT 1 FROM inventory_stock_movements WHERE batch_id = ? AND warehouse_id IN ({$in}) LIMIT 1", [$b['id']])
                || App::db()->scalar("SELECT 1 FROM inventory_entry_items i JOIN inventory_entries e ON e.id = i.entry_id WHERE i.batch_no = ? AND i.product_id = ? AND e.warehouse_id IN ({$in}) LIMIT 1", [$b['batch_no'], $b['product_id']]);
            if (!$seen) {
                throw new HttpException(404, 'Batch not found.');
            }
        }
        return $b;
    }

    /** @return array<string, mixed> */
    private function findSerial(string $id): array
    {
        $sid = $this->intParam($id);
        if (!$this->svc->canSeeSerial($this->user(), $sid)) {
            throw new HttpException(404, 'Serial number not found.');
        }
        return $this->svc->serial($sid) ?? throw new HttpException(404, 'Serial number not found.');
    }
}
