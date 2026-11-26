<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Inventory\InventoryHistory;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\WarehouseService;
use App\Support\BusinessDate;

/** Stock reservations: reserve free stock for a reference, release or cancel, automatic expiry. */
final class ReservationController extends InventoryBaseController
{
    private ReservationService $svc;

    public function __construct()
    {
        $this->svc = new ReservationService(App::db());
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status', 'type'], ['warehouse', 'product']);
        $rows = $this->svc->list($this->user(), $f);
        return $this->view('inventory/reservations/index', ['title' => 'Stock reservations', 'breadcrumbs' => self::CRUMB + ['Reservations' => null], 'f' => $f,
            'rows' => WarehouseController::pageOf($rows, $this->page($request), $this->perPage() * 2), 'warehouses' => $this->warehouseOptions(), 'items' => $this->itemOptions()]);
    }

    public function create(Request $request): Response
    {
        $ws = new WarehouseService(App::db());
        $options = $ws->options($this->user());
        $wid = (int) $request->input('warehouse', 0);
        $w = $wid > 0 && InventoryScope::allows($this->user(), $wid) ? $ws->find($wid) : (count($options) === 1 ? $ws->find((int) $options[0]['id']) : null);
        $days = (int) App::settings()->get('inventory_reservation_days', 7);
        return $this->view('inventory/reservations/form', ['title' => 'New reservation', 'breadcrumbs' => self::CRUMB + ['Reservations' => route('inventory.reservations')] + ['New' => null],
            'w' => $w, 'warehouses' => $options, 'items' => $this->itemOptions(), 'lines' => $this->oldLines([]), 'today' => BusinessDate::today(),
            'expires' => $days > 0 ? BusinessDate::addDays(BusinessDate::today(), $days) : '',
            'stock' => $w !== null ? (new StockQueryService(App::db()))->items($this->user(), ['warehouse' => (int) $w['id'], 'nonzero' => '1']) : []]);
    }

    public function store(Request $request): Response
    {
        $input = $this->withLines($request->all());
        InventoryScope::assert($this->user(), (int) ($input['warehouse_id'] ?? 0));
        $id = $this->svc->reserve($input, $this->user());
        return $this->redirectRoute('inventory.reservations.show', ['id' => $id])->with('success', 'Stock reserved. It is no longer free for other issues until fulfilled, released or expired.');
    }

    public function show(Request $request, string $id): Response
    {
        $r = $this->find($id);
        return $this->view('inventory/reservations/show', ['title' => $r['reservation_no'], 'breadcrumbs' => self::CRUMB + ['Reservations' => route('inventory.reservations'), $r['reservation_no'] => null],
            'r' => $r, 'items' => $this->svc->items((int) $r['id']),
            'history' => can('inventory.view_history') ? InventoryHistory::read(App::db(), "h.subject_type = 'reservation' AND h.subject_id = ?", [$r['id']]) : []]);
    }

    public function close(Request $request, string $id): Response
    {
        $r = $this->find($id);
        $this->authorize('inventory.release_reservations');
        $to = $request->string('status') === 'cancelled' ? 'cancelled' : 'released';
        $this->svc->close($r, $to, $request->string('reason'), $this->user());
        return $this->redirectRoute('inventory.reservations.show', ['id' => $r['id']])->with('success', $to === 'released' ? 'Reservation released — the stock is free again.' : 'Reservation cancelled.');
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $this->svc->expireDue();
        $r = $this->svc->find($this->intParam($id)) ?? throw new HttpException(404, 'Reservation not found.');
        InventoryScope::assert($this->user(), (int) $r['warehouse_id']);
        return $r;
    }
}
