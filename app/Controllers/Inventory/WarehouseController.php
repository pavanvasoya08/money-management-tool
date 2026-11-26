<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\EntryService;
use App\Services\Inventory\InventoryDocumentService;
use App\Services\Inventory\InventoryHistory;
use App\Services\Inventory\LocationService;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\TransferService;
use App\Services\Inventory\WarehouseService;
use App\Services\Product\DatedMappingService;

/** Warehouses: list, add / edit, the 9-tab warehouse page, status, archive, mappings, users, documents and locations. */
final class WarehouseController extends InventoryBaseController
{
    public const TABS = [
        'overview' => 'Overview', 'stock' => 'Stock', 'locations' => 'Locations', 'inward' => 'Inward', 'outward' => 'Outward', 'transfers' => 'Transfers',
        'adjustments' => 'Adjustments', 'documents' => 'Documents', 'history' => 'History',
    ];

    private WarehouseService $warehouses;

    public function __construct()
    {
        $this->warehouses = new WarehouseService(App::db());
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'status', 'archived'], ['type', 'state', 'gst', 'activity']);
        $rows = $this->warehouses->list($this->user(), $f);
        return $this->view('inventory/warehouses/index', ['title' => 'Warehouses', 'breadcrumbs' => self::CRUMB + ['Warehouses' => null], 'rows' => $rows, 'f' => $f,
            'types' => $this->warehouses->types(), 'states' => App::db()->select('SELECT id, name FROM gst_states ORDER BY name'), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(),
            'fields' => App::fields()->accessMap($this->user(), 'inventory')]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $input = $this->protect($request->all(), null);
        $id = $this->warehouses->create($input, $this->user());
        return $this->redirectRoute('inventory.warehouses.show', ['id' => $id])->with('success', 'Warehouse added. Add storage locations, GST registrations and business activities next.');
    }

    public function edit(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->assertWritable($w);
        return $this->form($w);
    }

    public function update(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->update($w, $this->protect($request->all(), $w), $this->user());
        return $this->redirectRoute('inventory.warehouses.show', ['id' => $w['id']])->with('success', 'Warehouse saved.');
    }

    public function show(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $wid = (int) $w['id'];
        $me = $this->user();
        $db = App::db();
        $tab = $request->string('tab', 'overview');
        if (!isset(self::TABS[$tab]) || ($tab === 'history' && !can('inventory.view_history'))) {
            $tab = 'overview';
        }
        $tabs = self::TABS;
        if (!can('inventory.view_history')) {
            unset($tabs['history']);
        }
        $q = new StockQueryService($db);
        $items = in_array($tab, ['overview', 'stock'], true) ? $q->items($me, ['warehouse' => $wid] + ($tab === 'stock' ? $this->filters($request, ['q', 'status'], ['category', 'brand']) : [])) : [];
        $data = ['title' => $w['name'], 'breadcrumbs' => self::CRUMB + ['Warehouses' => route('inventory.warehouses.index'), $w['code'] => null], 'w' => $w, 'tab' => $tab, 'tabs' => $tabs,
            'fields' => App::fields()->accessMap($me, 'inventory'), 'blocker' => $this->warehouses->blocker($wid),
            'counts' => ['locations' => (int) $db->scalar("SELECT COUNT(*) FROM warehouse_locations WHERE warehouse_id = ? AND status <> 'archived'", [$wid]),
                'documents' => (int) $db->scalar("SELECT COUNT(*) FROM inventory_documents WHERE subject_type = 'warehouse' AND subject_id = ? AND status = 'active'", [$wid])]];
        $page = $this->page($request);
        $per = $this->perPage();
        $data += match ($tab) {
            'overview' => ['items' => $items, 'gst' => (new DatedMappingService($db, 'warehouse_gst'))->list($wid), 'activities' => (new DatedMappingService($db, 'warehouse_activity'))->list($wid),
                'gstOptions' => $this->gstOptions(), 'activityOptions' => $this->activityOptions(), 'users' => $this->warehouses->assignedUsers($wid),
                'userOptions' => can('inventory.manage_warehouses') ? $db->select("SELECT id, name, email FROM users WHERE status = 'active' ORDER BY name") : [],
                'recent' => $q->movements($me, ['warehouse' => $wid], 8)],
            'stock' => ['items' => $items, 'f' => $this->filters($request, ['q', 'status'], ['category', 'brand']), 'categories' => $this->categoryOptions(), 'brands' => $this->brandOptions()],
            'locations' => ['tree' => (new LocationService($db))->tree($wid, $request->string('archived') === '1'), 'types' => LocationService::TYPES, 'units' => $this->unitOptions(),
                'edit' => (int) $request->input('edit', 0), 'showArchived' => $request->string('archived') === '1'],
            'inward' => ['docs' => (new EntryService($db))->paginate($me, 'inward', ['warehouse' => $wid], $page, $per), 'opening' => (new EntryService($db))->paginate($me, 'opening', ['warehouse' => $wid], 1, 50)],
            'outward' => ['docs' => (new EntryService($db))->paginate($me, 'outward', ['warehouse' => $wid], $page, $per)],
            'transfers' => ['docs' => (new TransferService($db))->paginate($me, ['warehouse' => $wid], $page, $per)],
            'adjustments' => ['docs' => (new AdjustmentService($db))->paginate($me, ['warehouse' => $wid], $page, $per)],
            'documents' => ['documents' => $this->documentsFor($wid, $request->string('archived') === '1'), 'categories' => InventoryDocumentService::CATEGORIES, 'showArchived' => $request->string('archived') === '1'],
            'history' => ['history' => InventoryHistory::read($db, 'h.warehouse_id = ?', [$wid], 300),
                'audit' => $db->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.subject_type = 'warehouse' AND l.subject_id = ? ORDER BY l.id DESC LIMIT 100", [$wid])],
        };
        return $this->view('inventory/warehouses/show', $data);
    }

    public function status(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->changeStatus($w, $request->string('status'), $request->string('reason'), $this->user());
        return $this->redirectRoute('inventory.warehouses.show', ['id' => $w['id']])->with('success', 'Warehouse status changed.');
    }

    public function archive(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->archive($w, $request->string('reason'), $this->user());
        return $this->redirectRoute('inventory.warehouses.show', ['id' => $w['id']])->with('success', 'Warehouse archived. Its history stays available.');
    }

    public function restore(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->restore($w, $this->user());
        return $this->redirectRoute('inventory.warehouses.show', ['id' => $w['id']])->with('success', 'Warehouse restored as inactive. Activate it to use it again.');
    }

    public function destroy(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->delete($w, $this->user());
        return $this->redirectRoute('inventory.warehouses.index')->with('success', "Warehouse {$w['code']} deleted.");
    }

    public function addGst(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->assertWritable($w);
        $gst = (int) $request->input('gst_registration_id', 0);
        if (!in_array($gst, array_map(static fn (array $g): int => (int) $g['id'], $this->gstOptions()), true)) {
            throw ValidationException::withMessage('gst_registration_id', 'Choose a GST registration you can see.');
        }
        (new DatedMappingService(App::db(), 'warehouse_gst'))->add($w, $gst, $request->all(), $this->user());
        return $this->toTab($w, 'overview', 'GST registration linked.');
    }

    public function endGst(Request $request, string $id, string $mapping): Response
    {
        $w = $this->findWarehouse($id);
        (new DatedMappingService(App::db(), 'warehouse_gst'))->end($w, $this->intParam($mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->toTab($w, 'overview', 'GST link ended. It stays in the history.');
    }

    public function addActivity(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->assertWritable($w);
        (new DatedMappingService(App::db(), 'warehouse_activity'))->add($w, (int) $request->input('business_activity_id', 0), $request->all(), $this->user());
        return $this->toTab($w, 'overview', 'Business activity linked.');
    }

    public function endActivity(Request $request, string $id, string $mapping): Response
    {
        $w = $this->findWarehouse($id);
        (new DatedMappingService(App::db(), 'warehouse_activity'))->end($w, $this->intParam($mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->toTab($w, 'overview', 'Business activity link ended.');
    }

    public function assign(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->assertWritable($w);
        $uid = (int) $request->input('user_id', 0);
        if (!App::db()->scalar("SELECT 1 FROM users WHERE id = ?", [$uid])) {
            throw ValidationException::withMessage('user_id', 'Choose a user.');
        }
        $assign = $request->string('assigned', '1') === '1';
        $this->warehouses->setAssignment((int) $w['id'], $uid, $assign, $this->user());
        return $this->toTab($w, 'overview', $assign ? 'User assigned to the warehouse.' : 'User removed from the warehouse.');
    }

    public function uploadDoc(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $this->warehouses->assertWritable($w);
        $this->uploadDocument($request, 'warehouse', (int) $w['id'], (int) $w['id']);
        return $this->toTab($w, 'documents', 'Document uploaded.');
    }

    // ---------------------------------------------------------------- storage locations

    public function locations(Request $request): Response
    {
        $options = $this->warehouseOptions();
        $wid = (int) $request->input('warehouse', 0) ?: (int) ($options[0]['id'] ?? 0);
        if ($wid === 0) {
            return $this->view('inventory/locations', ['title' => 'Storage locations', 'breadcrumbs' => self::CRUMB + ['Storage locations' => null], 'w' => null, 'options' => [], 'tree' => []]);
        }
        $w = $this->findWarehouse($wid);
        return $this->view('inventory/locations', ['title' => 'Storage locations', 'breadcrumbs' => self::CRUMB + ['Storage locations' => null], 'w' => $w, 'options' => $options,
            'tree' => (new LocationService(App::db()))->tree($wid, $request->string('archived') === '1'), 'types' => LocationService::TYPES, 'units' => $this->unitOptions(),
            'edit' => (int) $request->input('edit', 0), 'showArchived' => $request->string('archived') === '1']);
    }

    public function saveLocation(Request $request, string $id): Response
    {
        $w = $this->findWarehouse($id);
        $svc = new LocationService(App::db());
        $loc = null;
        if (($lid = (int) $request->input('location_id', 0)) > 0) {
            $loc = $svc->find($lid);
            if ($loc === null || (int) $loc['warehouse_id'] !== (int) $w['id']) {
                throw new HttpException(404, 'Location not found.');
            }
        }
        $svc->save($w, $request->all(), $loc, $this->user());
        return $this->redirect($this->locationsUrl($request, $w))->with('success', $loc === null ? 'Location added.' : 'Location saved.');
    }

    public function locationStatus(Request $request, string $id, string $location): Response
    {
        $w = $this->findWarehouse($id);
        $svc = new LocationService(App::db());
        $loc = $svc->find($this->intParam($location));
        if ($loc === null || (int) $loc['warehouse_id'] !== (int) $w['id']) {
            throw new HttpException(404, 'Location not found.');
        }
        $svc->setStatus($loc, $request->string('status'), $request->string('reason'), $this->user());
        return $this->redirect($this->locationsUrl($request, $w))->with('success', 'Location status changed.');
    }

    // ---------------------------------------------------------------- internals

    private function form(?array $w): Response
    {
        return $this->view('inventory/warehouses/form', [
            'title' => $w === null ? 'Add warehouse' : 'Edit ' . $w['name'],
            'breadcrumbs' => self::CRUMB + ['Warehouses' => route('inventory.warehouses.index')] + ($w !== null ? [$w['code'] => route('inventory.warehouses.show', ['id' => $w['id']])] : []) + [$w === null ? 'Add' : 'Edit' => null],
            'w' => $w, 'types' => $this->warehouses->types(true), 'states' => App::db()->select('SELECT id, name, code FROM gst_states ORDER BY name'),
            'fields' => App::fields()->accessMap($this->user(), 'inventory'),
        ]);
    }

    /** Users who may not edit a protected field keep the stored value. @param array<string, mixed> $input @param array<string, mixed>|null $w @return array<string, mixed> */
    private function protect(array $input, ?array $w): array
    {
        $me = $this->user();
        $groups = ['warehouse_contact' => ['contact_person', 'mobile', 'email'], 'internal_notes' => ['internal_notes']];
        foreach ($groups as $field => $keys) {
            if (App::fields()->access($me, 'inventory', $field)->canEdit()) {
                continue;
            }
            foreach ($keys as $k) {
                $input[$k] = $w[$k] ?? '';
            }
        }
        return $input;
    }

    /** @return list<array<string, mixed>> */
    private function documentsFor(int $wid, bool $archived): array
    {
        if (!can('inventory.download_documents') && !can('inventory.manage_documents')) {
            return [];
        }
        return (new InventoryDocumentService(App::db()))->list('warehouse', $wid, $archived);
    }

    /** @param array<string, mixed> $w */
    private function toTab(array $w, string $tab, string $message): Response
    {
        return $this->redirectRoute('inventory.warehouses.show', ['id' => $w['id'], 'tab' => $tab])->with('success', $message);
    }

    /** @param array<string, mixed> $w */
    private function locationsUrl(Request $request, array $w): string
    {
        return $request->string('back') === 'page' ? route('inventory.locations', ['warehouse' => $w['id']]) : route('inventory.warehouses.show', ['id' => $w['id'], 'tab' => 'locations']);
    }

    /** Paginator for lists computed in memory. @param list<array<string, mixed>> $rows */
    public static function pageOf(array $rows, int $page, int $per): Paginator
    {
        return new Paginator(array_slice($rows, ($page - 1) * $per, $per), count($rows), $page, $per);
    }
}
