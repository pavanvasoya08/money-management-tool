<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Inventory\EntryService;
use App\Services\Inventory\LocationService;
use App\Services\Inventory\ProductInventoryService;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\TransferService;
use App\Services\Inventory\WarehouseService;
use App\Services\Product\ProductService;
use App\Support\BusinessDate;

/** Phase 7 through real HTTP requests: every page renders, warehouse scope, cost visibility, field rules, stock rules, exports and CSRF. */
final class InventorySecurityTest extends HttpTestCase
{
    private array $root;
    private int $whA;
    private int $whB;
    private int $soap;
    private int $phone;

    public function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $ws = new WarehouseService($db);
        $type = static fn (string $c): int => (int) $db->scalar('SELECT id FROM warehouse_types WHERE code = ?', [$c]);
        $this->whA = $ws->create(['name' => 'Ahmedabad Central', 'warehouse_type_id' => $type('main'), 'city' => 'Ahmedabad', 'contact_person' => 'Secret Keeper', 'mobile' => '9876500011'], $this->root);
        $this->whB = $ws->create(['name' => 'Jaipur Store', 'warehouse_type_id' => $type('retail_store'), 'city' => 'Jaipur'], $this->root);
        (new LocationService($db))->save($ws->find($this->whA), ['code' => 'RACK-A', 'name' => 'Rack A', 'location_type' => 'rack'], null, $this->root);
        $products = new ProductService($db);
        $pt = (int) $db->scalar("SELECT id FROM product_types WHERE code = 'physical'");
        $this->soap = $products->create(['name' => 'Neem Soap', 'product_type_id' => $pt, 'unit' => 'pcs'], $this->root, true)[0];
        $this->phone = $products->create(['name' => 'Feature Phone', 'product_type_id' => $pt, 'unit' => 'pcs'], $this->root, true)[0];
        $pis = new ProductInventoryService($db);
        $pis->save($this->soap, ['batch_tracking' => 1, 'expiry_tracking' => 1, 'reorder_level' => '50'], $this->root);
        $pis->save($this->phone, ['serial_tracking' => 1], $this->root);
        $entries = new EntryService($db);
        $today = BusinessDate::today();
        $id = $entries->save('opening', ['warehouse_id' => $this->whA, 'entry_date' => $today, 'lines' => [
            ['product_id' => $this->soap, 'quantity' => '40', 'unit_cost' => '12.50', 'batch_no' => 'NS-01', 'expiry_date' => BusinessDate::addDays($today, 10)],
            ['product_id' => $this->phone, 'quantity' => '2', 'unit_cost' => '1999', 'serial_numbers' => "IMEI001\nIMEI002"],
        ]], null, $this->root);
        $entries->submit($entries->find($id), $this->root);
        $id = $entries->save('opening', ['warehouse_id' => $this->whB, 'entry_date' => $today, 'lines' => [['product_id' => $this->soap, 'quantity' => '5', 'unit_cost' => '13', 'batch_no' => 'NS-02', 'expiry_date' => BusinessDate::addDays($today, 200)]]], null, $this->root);
        $entries->submit($entries->find($id), $this->root);
        $out = $entries->save('outward', ['warehouse_id' => $this->whA, 'entry_type' => 'consumption', 'entry_date' => $today, 'reason' => 'Staff use', 'lines' => [['product_id' => $this->soap, 'quantity' => '3']]], null, $this->root);
        $entries->submit($entries->find($out), $this->root);
        $tr = new TransferService($db);
        $t = $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whB, 'transfer_date' => $today, 'lines' => [['product_id' => $this->soap, 'quantity' => '4']]], null, $this->root);
        $tr->submit($tr->find($t), $this->root);
        (new ReservationService($db))->reserve(['reference_type' => 'internal', 'reference_no' => 'REQ-7', 'warehouse_id' => $this->whA, 'lines' => [['product_id' => $this->soap, 'quantity' => '2']]], $this->root);
        $acct = $this->makeUser('acct@example.com', ['accountant'], name: 'Accountant');
        $this->makeUser('viewer@example.com', ['viewer'], name: 'Viewer');
        $this->makeUser('manager@example.com', ['manager'], name: 'Manager');
        // The accountant sees only the Jaipur store.
        $perms = new PermissionService($db);
        $perms->setUserOverrides((int) $acct['id'], [$this->permission('inventory.view_all') => 'deny', $this->permission('inventory.view_assigned') => 'allow'], $this->root, null, 'Store accountant');
        (new ResourceAssignmentService($db))->sync((int) $acct['id'], 'warehouse', [$this->whB], $this->root);
    }

    private function permission(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
    }

    /** @return list<string> */
    private function pages(): array
    {
        $db = App::db();
        $one = static fn (string $sql): int => (int) $db->scalar($sql);
        $pages = ['/inventory', '/inventory?warehouse=' . $this->whA . '&status=low', '/inventory/stock', '/inventory/stock?all=1', '/inventory/warehouses', '/inventory/warehouses/create',
            '/inventory/locations', '/inventory/locations?warehouse=' . $this->whA, '/inventory/products', '/inventory/opening', '/inventory/inward', '/inventory/outward',
            '/inventory/opening/create', '/inventory/opening/create?warehouse=' . $this->whA, '/inventory/inward/create?warehouse=' . $this->whA, '/inventory/outward/create?warehouse=' . $this->whA,
            '/inventory/transfers', '/inventory/transfers/create', '/inventory/transfers/create?from=' . $this->whA . '&to=' . $this->whB, '/inventory/transfers/create?from=' . $this->whA . '&to=' . $this->whA,
            '/inventory/adjustments', '/inventory/adjustments/create?warehouse=' . $this->whA, '/inventory/batches', '/inventory/batches?stock=all&expiry=near', '/inventory/serials',
            '/inventory/reservations', '/inventory/reservations/create?warehouse=' . $this->whA, '/inventory/ledger', '/inventory/ledger?warehouse=' . $this->whA . '&type=transit_out',
            '/inventory/valuation', '/inventory/low-stock', '/inventory/history', '/inventory/reports', '/inventory/settings', '/inventory/settings?tab=units', '/inventory/settings?tab=types',
            '/inventory/batches/' . $one('SELECT MIN(id) FROM inventory_batches'), '/inventory/serials/' . $one('SELECT MIN(id) FROM inventory_serial_numbers'),
            '/inventory/reservations/' . $one('SELECT MIN(id) FROM inventory_reservations'), '/inventory/transfers/' . $one('SELECT MIN(id) FROM inventory_transfers'),
            '/inventory/opening/' . $one("SELECT MIN(id) FROM inventory_entries WHERE direction = 'opening'"), '/inventory/outward/' . $one("SELECT MIN(id) FROM inventory_entries WHERE direction = 'outward'"),
            '/inventory/warehouses/' . $this->whA . '/edit'];
        foreach (array_keys(\App\Controllers\Inventory\WarehouseController::TABS) as $tab) {
            $pages[] = '/inventory/warehouses/' . $this->whA . '?tab=' . $tab;
        }
        foreach (array_keys(\App\Controllers\Inventory\ProductInventoryController::TABS) as $tab) {
            $pages[] = '/inventory/products/' . $this->soap . '?tab=' . $tab;
            $pages[] = '/inventory/products/' . $this->phone . '?tab=' . $tab;
        }
        foreach (array_keys(\App\Services\Inventory\InventoryReportService::REPORTS) as $r) {
            $pages[] = '/inventory/reports/' . $r;
        }
        return $pages;
    }

    public function testEveryPageRendersForSuperAdmin(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        foreach ($this->pages() as $url) {
            $r = $b->get($url);
            $this->assertSame(200, $r['status'], $url . ' → ' . mb_substr(strip_tags($r['body']), 0, 300));
            foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught', 'Stack trace'] as $bad) {
                $this->assertNotContains($bad, $r['body'], "{$url} contains {$bad}");
            }
        }
        $dash = $b->get('/inventory')['body'];
        $this->assertContains('Inventory products', $dash);
        $this->assertContains('Neem Soap', $b->get('/inventory/stock')['body']);
        foreach (array_keys(\App\Services\Inventory\InventoryReportService::REPORTS) as $r) {
            $csv = $b->get('/inventory/reports/' . $r . '/export?format=csv');
            $this->assertSame(200, $csv['status'], "export {$r}");
        }
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'inventory.report_exported' AND description LIKE '%Inventory valuation%'"));
    }

    public function testViewerHasNoAccessAndScopedUserSeesOnlyAssignedWarehouse(): void
    {
        $v = $this->browser();
        $this->signIn($v, 'viewer@example.com');
        foreach (['/inventory', '/inventory/stock', '/inventory/warehouses', '/inventory/warehouses/' . $this->whA, '/inventory/reports/summary', '/inventory/ledger'] as $url) {
            $this->assertSame(403, $v->get($url)['status'], $url);
        }
        $a = $this->browser();
        $this->signIn($a, 'acct@example.com');
        $list = $a->get('/inventory/warehouses')['body'];
        $this->assertContains('Jaipur Store', $list);
        $this->assertNotContains('Ahmedabad Central', $list);
        $this->assertSame(404, $a->get('/inventory/warehouses/' . $this->whA)['status'], 'warehouse outside the scope is a 404');
        $this->assertSame(404, $a->get('/inventory/opening/' . App::db()->scalar("SELECT MIN(id) FROM inventory_entries WHERE warehouse_id = ?", [$this->whA]))['status']);
        $this->assertSame(404, $a->get('/inventory/reservations/' . App::db()->scalar('SELECT MIN(id) FROM inventory_reservations'))['status']);
        $this->assertSame(404, $a->get('/inventory/serials/' . App::db()->scalar('SELECT MIN(id) FROM inventory_serial_numbers'))['status'], 'serials never in the store are hidden');
        // The transfer to Jaipur is visible (destination in scope); stock figures cover Jaipur only.
        $this->assertSame(200, $a->get('/inventory/transfers/' . App::db()->scalar('SELECT MIN(id) FROM inventory_transfers'))['status']);
        $csv = $a->get('/inventory/reports/summary/export?format=csv')['body'];
        $this->assertContains('Jaipur Store', $csv);
        $this->assertNotContains('Ahmedabad', $csv, 'exports are warehouse-scoped');
        foreach (['/inventory', '/inventory/stock', '/inventory/ledger', '/inventory/reports/ledger', '/inventory/batches', '/inventory/products/' . $this->soap . '?tab=warehouses'] as $url) {
            $this->assertNotContains('Ahmedabad Central', $a->get($url)['body'], $url);
        }
        // Read-only role: no stock documents.
        $this->assertSame(403, $a->get('/inventory/inward/create')['status']);
        $a->get('/inventory/inward');
        $this->assertSame(403, $a->post('/inventory/inward', ['warehouse_id' => $this->whB, 'entry_type' => 'other_receipt'])['status']);
    }

    public function testCostVisibilityFieldRulesAndStockRules(): void
    {
        $db = App::db();
        $m = $this->browser();
        $this->signIn($m, 'manager@example.com');
        // The manager role has no inventory.view_stock_cost: values and costs are hidden everywhere.
        $this->assertSame(403, $m->get('/inventory/valuation')['status']);
        $this->assertNotContains('₹500.00', $m->get('/inventory/stock')['body']);
        $csv = $m->get('/inventory/reports/valuation/export?format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertNotContains('Value', explode("\n", $csv['body'])[2] ?? '', 'value column dropped without cost permission');
        // Manager may record an outward but not take stock below zero.
        $m->get('/inventory/outward/create?warehouse=' . $this->whB);
        $r = $m->post('/inventory/outward', ['warehouse_id' => $this->whB, 'entry_type' => 'consumption', 'entry_date' => BusinessDate::today(), 'reason' => 'Test', 'action' => 'submit',
            'lines' => [1 => ['item' => $this->soap . ':', 'quantity' => '999']]]);
        $this->assertTrue(in_array($r['status'], [302, 303], true));
        $doc = $db->first("SELECT * FROM inventory_entries WHERE direction = 'outward' AND warehouse_id = ? ORDER BY id DESC LIMIT 1", [$this->whB]);
        $this->assertSame('approved', $doc['status'], 'approved but not posted');
        $this->assertContains('Not enough free stock', (string) $doc['posting_error']);
        $this->assertSame('0', (string) $db->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE source_type = 'outward' AND source_id = ?", [$doc['id']]));
        // Field rule: warehouse contact hidden for the manager.
        $mid = (int) $db->scalar("SELECT id FROM users WHERE email = 'manager@example.com'");
        $fid = (int) $db->scalar("SELECT f.id FROM module_fields f JOIN modules mo ON mo.id = f.module_id WHERE mo.module_key = 'inventory' AND f.field_key = 'warehouse_contact'");
        (new PermissionService($db))->setUserFieldAccess($mid, [$fid => 'hidden'], $this->root);
        $m2 = $this->browser();
        $this->signIn($m2, 'manager@example.com');
        $this->assertNotContains('Secret Keeper', $m2->get('/inventory/warehouses/' . $this->whA)['body'], 'hidden field not rendered');
        // CSRF is enforced.
        $this->assertSame(419, $m2->post('/inventory/transfers/' . $db->scalar('SELECT MIN(id) FROM inventory_transfers') . '/cancel', ['reason' => 'x'], false)['status']);
        // Super admin sees values.
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $this->assertContains('₹', $b->get('/inventory/valuation')['body']);
    }

    public function testLineEditorRoundTripThroughHttp(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/inventory/inward/create?warehouse=' . $this->whA);
        $r = $b->post('/inventory/inward', ['warehouse_id' => $this->whA, 'entry_type' => 'other_receipt', 'entry_date' => BusinessDate::today(), 'reason' => 'Found in back room', 'action' => 'draft',
            'lines' => [1 => ['item' => $this->soap . ':', 'quantity' => '1', 'unit_code' => 'dozen', 'unit_cost' => '120', 'batch_no' => 'ns-09', 'expiry_date' => BusinessDate::addDays(BusinessDate::today(), 90), 'location_id' => '']]]);
        $this->assertTrue(in_array($r['status'], [302, 303], true), 'draft saved');
        $e = App::db()->first("SELECT * FROM inventory_entries WHERE direction = 'inward' ORDER BY id DESC LIMIT 1");
        $line = App::db()->first('SELECT * FROM inventory_entry_items WHERE entry_id = ?', [$e['id']]);
        $this->assertSame(120000, \App\Support\Qty::fromDb($line['base_quantity']), '1 dozen = 12 pieces');
        $this->assertSame(100000, \App\Support\Qty::fromDb($line['unit_cost']), '₹120 per dozen = ₹10 per piece');
        $this->assertSame('NS-09', (string) $line['batch_no']);
        $edit = $b->get('/inventory/inward/' . $e['id'] . '/edit');
        $this->assertSame(200, $edit['status']);
        $this->assertContains('value="12"', $edit['body'], 'edit form shows the stock-unit quantity');
        $r = $b->post('/inventory/inward/' . $e['id'] . '/submit', []);
        $this->assertTrue(in_array($r['status'], [302, 303], true));
        $this->assertSame('posted', App::db()->scalar('SELECT status FROM inventory_entries WHERE id = ?', [$e['id']]));
        // A validation error keeps the lines the user typed.
        $b->get('/inventory/opening/create?warehouse=' . $this->whB);
        $b->post('/inventory/opening', ['warehouse_id' => $this->whB, 'entry_date' => BusinessDate::today(), 'lines' => [1 => ['item' => $this->phone . ':', 'quantity' => '2', 'unit_cost' => '10', 'serial_numbers' => 'ONLYONE']]]);
        $form = $b->get('/inventory/opening/create?warehouse=' . $this->whB)['body'];
        $this->assertContains('enter exactly 2 serial numbers', $form);
        $this->assertContains('ONLYONE', $form);
    }
}
