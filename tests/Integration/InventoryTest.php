<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Admin\WorkflowService;
use App\Services\Inventory\AdjustmentService;
use App\Services\Inventory\BatchSerialService;
use App\Services\Inventory\CorrectionService;
use App\Services\Inventory\EntryService;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\InventoryScope;
use App\Services\Inventory\LocationService;
use App\Services\Inventory\ProductInventoryService;
use App\Services\Inventory\ReservationService;
use App\Services\Inventory\StockQueryService;
use App\Services\Inventory\TransferService;
use App\Services\Inventory\UnitService;
use App\Services\Inventory\WarehouseService;
use App\Services\Product\DatedMappingService;
use App\Services\Product\ProductService;
use App\Services\Product\VariantService;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Qty;
use Tests\TestCase;

/** Phase 7: warehouses, locations, units, stock documents, ledger, valuation, batches, serials, reservations, approvals and scope at the service layer. */
final class InventoryTest extends TestCase
{
    private array $root;
    private int $whA;
    private int $whB;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $ws = new WarehouseService(App::db());
        $this->whA = $ws->create(['name' => 'Main Warehouse', 'warehouse_type_id' => $this->wtype('main'), 'city' => 'Ahmedabad', 'state_id' => $this->state('24'), 'pin_code' => '380001'], $this->root);
        $this->whB = $ws->create(['name' => 'Jaipur Store', 'warehouse_type_id' => $this->wtype('retail_store'), 'city' => 'Jaipur', 'state_id' => $this->state('08')], $this->root);
    }

    private function wtype(string $code): int
    {
        return (int) App::db()->scalar('SELECT id FROM warehouse_types WHERE code = ?', [$code]);
    }

    private function state(string $code): int
    {
        return (int) App::db()->scalar('SELECT id FROM gst_states WHERE code = ?', [$code]);
    }

    /** @param array<string, mixed> $settings */
    private function product(string $name, array $settings = [], string $unit = 'pcs'): array
    {
        $type = (int) App::db()->scalar("SELECT id FROM product_types WHERE code = 'physical'");
        [$id] = (new ProductService(App::db()))->create(['name' => $name, 'product_type_id' => $type, 'unit' => $unit], $this->root, true);
        if ($settings !== []) {
            (new ProductInventoryService(App::db()))->save($id, $settings, $this->root);
        }
        return (new ProductService(App::db()))->find($id);
    }

    private function workflow(string $key): void
    {
        $wf = (int) App::db()->scalar('SELECT id FROM approval_workflows WHERE workflow_key = ?', [$key]);
        (new WorkflowService(App::db()))->saveSteps($wf, [['name' => 'Review', 'approver_type' => 'permission', 'min_amount' => '0']], $this->root);
        (new WorkflowService(App::db()))->update($wf, true, null, $this->root);
    }

    private function setting(string $key, mixed $value): void
    {
        $input = [];
        foreach (\App\Services\SettingsService::definitions() as $k => $d) {
            if ($d['group'] === 'inventory') {
                $v = App::settings()->get($k);
                $input[$k] = is_bool($v) ? ($v ? '1' : '0') : (string) $v;
            }
        }
        $input[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        App::settings()->update($input, (int) $this->root['id'], ['inventory']);
    }

    /**
     * Create, submit (auto-approved while the workflow is inactive) and return a posted entry.
     * @param list<array<string, mixed>> $lines @param array<string, mixed> $extra
     */
    private function entry(string $direction, string $type, int $wh, array $lines, array $extra = [], ?array $actor = null): array
    {
        $svc = new EntryService(App::db());
        $id = $svc->save($direction, $extra + ['entry_type' => $type, 'warehouse_id' => $wh, 'entry_date' => BusinessDate::today(), 'reason' => 'Test', 'lines' => $lines], null, $actor ?? $this->root);
        $svc->submit($svc->find($id), $actor ?? $this->root);
        return $svc->find($id);
    }

    private function onHand(int $wh, int $product, ?int $variant = null, string $state = 'sellable'): string
    {
        $item = App::db()->first('SELECT id FROM inventory_stock_items WHERE item_key = ?', [$wh . ':' . $product . ':' . ($variant ?? 0)]);
        return $item === null ? '0' : Qty::format((new InventoryLedger(App::db()))->stateQty((int) $item['id'], $state));
    }

    private function value(int $wh, int $product, ?int $variant = null): string
    {
        return Money::toDb(Money::fromDb(App::db()->scalar('SELECT stock_value FROM inventory_stock_items WHERE item_key = ?', [$wh . ':' . $product . ':' . ($variant ?? 0)])));
    }

    public function testWarehousesLocationsMappingsAndArchiveRules(): void
    {
        $ws = new WarehouseService(App::db());
        $a = $ws->find($this->whA);
        $this->assertSame('WH-001', $a['code']);
        $this->assertSame(36, strlen($a['uuid']));
        $this->assertThrows(ValidationException::class, fn () => $ws->create(['name' => 'Dup', 'code' => 'WH-001', 'warehouse_type_id' => $this->wtype('main')], $this->root), 'already used');
        $this->assertThrows(ValidationException::class, fn () => $ws->create(['name' => 'Bad PIN', 'warehouse_type_id' => $this->wtype('main'), 'pin_code' => '12'], $this->root), 'PIN');
        $locs = new LocationService(App::db());
        $zone = $locs->save($a, ['code' => 'Z1', 'name' => 'Zone 1', 'location_type' => 'zone'], null, $this->root);
        $rack = $locs->save($a, ['code' => 'R-A', 'name' => 'Rack A', 'location_type' => 'rack', 'parent_id' => $zone], null, $this->root);
        $bin = $locs->save($a, ['code' => 'A-01', 'name' => 'Bin 1', 'location_type' => 'bin', 'parent_id' => $rack, 'capacity' => '50'], null, $this->root);
        $tree = $locs->tree($this->whA);
        $this->assertSame(['Z1', 'Z1 › R-A', 'Z1 › R-A › A-01'], array_column($tree, 'path'));
        $this->assertThrows(ValidationException::class, fn () => $locs->save($a, ['code' => 'Z1', 'name' => 'Zone 1', 'location_type' => 'zone', 'parent_id' => $bin], $locs->find($zone), $this->root), 'under itself');
        $this->assertThrows(ValidationException::class, fn () => $locs->save($a, ['code' => 'A-01', 'name' => 'x', 'location_type' => 'bin'], null, $this->root), 'already used');
        // a location of another warehouse cannot be used
        $other = $locs->save($ws->find($this->whB), ['code' => 'S1', 'name' => 'Shelf 1', 'location_type' => 'shelf'], null, $this->root);
        $this->assertThrows(ValidationException::class, fn () => $locs->resolve($this->whA, $other, false));
        // GST registration and business activity mappings reuse the Phase 4 masters
        $gst = (new \App\Services\Gst\GstRegistrationService(App::db()))->create(['gstin' => GstRegistrationTest::g('24AAACM1234A1Z'), 'legal_name' => 'Mehta Traders',
            'registration_type_id' => (int) App::db()->scalar("SELECT id FROM gst_registration_types WHERE code = 'regular'"), 'status' => 'active', 'addr_line1' => '1 Road',
            'addr_city' => 'City', 'addr_state_id' => $this->state('24'), 'addr_pin_code' => '380001'], $this->root)[0];
        (new DatedMappingService(App::db(), 'warehouse_gst'))->add($a, $gst, ['effective_from' => '2026-04-01'], $this->root);
        $act = (int) App::db()->scalar('SELECT id FROM gst_business_activities ORDER BY id LIMIT 1');
        (new DatedMappingService(App::db(), 'warehouse_activity'))->add($a, $act, [], $this->root);
        $this->assertSame([$this->whA], array_map('intval', array_column($ws->list($this->root, ['gst' => $gst]), 'id')));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_history WHERE subject_type = 'warehouse' AND event = 'gst_mapping_added'"));
        // stock blocks archiving and deletion; an unused warehouse can be deleted
        $p = $this->product('Brass Lamp');
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '5', 'unit_cost' => '100', 'location_id' => $bin]]);
        $this->assertThrows(DomainRuleException::class, fn () => $ws->archive($ws->find($this->whA), 'Closing', $this->root), 'holds stock');
        $this->assertThrows(DomainRuleException::class, fn () => $ws->delete($ws->find($this->whA), $this->root), 'cannot be deleted');
        $this->assertThrows(DomainRuleException::class, fn () => $locs->setStatus($locs->find($bin), 'archived', 'x', $this->root), 'holds stock');
        $c = $ws->create(['name' => 'Temp', 'warehouse_type_id' => $this->wtype('other')], $this->root);
        $ws->delete($ws->find($c), $this->root);
        $this->assertNull($ws->find($c));
        $ws->changeStatus($ws->find($this->whB), 'inactive', 'Renovation', $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry('opening', 'opening', $this->whB, [['product_id' => $p['id'], 'quantity' => '1', 'unit_cost' => '1']]), 'inactive');
    }

    public function testUnitsConversionsAndProductInventorySettings(): void
    {
        $units = new UnitService(App::db());
        $ctn = $units->saveUnit(['code' => 'case12', 'name' => 'Case of 12', 'unit_type' => 'count', 'decimals' => 0], null, $this->root);
        $pcs = (int) App::db()->scalar("SELECT id FROM inventory_units WHERE code = 'pcs'");
        $dozen = (int) App::db()->scalar("SELECT id FROM inventory_units WHERE code = 'dozen'");
        $p = $this->product('Stoneware Mug');
        $units->addConversion(['product_id' => $p['id'], 'from_unit_id' => $ctn, 'to_unit_id' => $dozen, 'factor' => '1'], $this->root);
        $this->assertSame(Qty::parse('24'), $units->toStockUnit((int) $p['id'], 'case12', 'pcs', Qty::parse('2')), 'case → dozen → pcs');
        // circular or duplicate conversions are refused
        $this->assertThrows(ValidationException::class, fn () => $units->addConversion(['product_id' => $p['id'], 'from_unit_id' => $pcs, 'to_unit_id' => $ctn, 'factor' => '0.1'], $this->root), 'already connected');
        $this->assertThrows(ValidationException::class, fn () => $units->addConversion(['from_unit_id' => $pcs, 'to_unit_id' => $dozen, 'factor' => '0.0833'], $this->root), 'already connected');
        $this->assertThrows(ValidationException::class, fn () => $units->addConversion(['from_unit_id' => $pcs, 'to_unit_id' => $pcs, 'factor' => '1'], $this->root), 'itself');
        $this->assertTrue(isset($units->enterableUnits((int) $p['id'], 'pcs')['case12']));
        // receive 2 cases = 24 pcs; a cost per case becomes a cost per piece
        $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '2', 'unit_code' => 'case12', 'unit_cost' => '1200']]);
        $this->assertSame('24', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame('2400.00', $this->value($this->whA, (int) $p['id']));
        // settings validation and locks once stock exists
        $pis = new ProductInventoryService(App::db());
        $this->assertThrows(ValidationException::class, fn () => $pis->save((int) $p['id'], ['expiry_tracking' => 1], $this->root), 'batch tracking');
        $this->assertThrows(ValidationException::class, fn () => $pis->save((int) $p['id'], ['min_qty' => '10', 'max_qty' => '5'], $this->root), 'below minimum');
        $this->assertThrows(ValidationException::class, fn () => $pis->save((int) $p['id'], ['batch_tracking' => 1], $this->root), 'no stock on hand');
        $this->assertThrows(ValidationException::class, fn () => $pis->save((int) $p['id'], ['valuation_method' => 'fifo'], $this->root), 'no stock on hand');
        $this->assertThrows(ValidationException::class, fn () => (new ProductService(App::db()))->update($p, ['unit' => 'box'] + $p, $this->root), 'stock movements');
        // a product that does not track inventory cannot move stock
        $svc = $this->product('Gift wrapping');
        App::db()->update('products', ['tracks_inventory' => 0], ['id' => $svc['id']]);
        $this->assertThrows(ValidationException::class, fn () => $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $svc['id'], 'quantity' => '1']]), 'does not track inventory');
    }

    public function testVariantLevelStockOpeningStockAndDuplicatePrevention(): void
    {
        $p = $this->product('Cushion Cover');
        $vs = new VariantService(App::db());
        $size = (int) App::db()->scalar("SELECT id FROM product_attributes WHERE code = 'size'");
        $cat = new \App\Services\Product\CatalogService(App::db());
        $s16 = $cat->saveAttributeValue($size, '16 in', 1, true, $this->root);
        $s18 = $cat->saveAttributeValue($size, '18 in', 2, true, $this->root);
        $v16 = $vs->create($p, ['attributes' => [$size => $s16]], $this->root);
        $v18 = $vs->create($p, ['attributes' => [$size => $s18]], $this->root);
        $pis = new ProductInventoryService(App::db());
        $this->assertSame('variant', $pis->config((int) $p['id'])['track_level']);
        $this->assertThrows(ValidationException::class, fn () => $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '5', 'unit_cost' => '100']]), 'choose a variant');
        $e = $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'variant_id' => $v16, 'quantity' => '10', 'unit_cost' => '120'],
            ['product_id' => $p['id'], 'variant_id' => $v18, 'quantity' => '4', 'unit_cost' => '150']]);
        $this->assertSame('posted', $e['status']);
        $this->assertSame('10', $this->onHand($this->whA, (int) $p['id'], $v16));
        $this->assertSame('4', $this->onHand($this->whA, (int) $p['id'], $v18));
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE source_type = 'opening' AND source_id = ?", [$e['id']]));
        // opening stock is recorded once per warehouse and item
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'variant_id' => $v16, 'quantity' => '1', 'unit_cost' => '1']]), 'already recorded');
        // posting twice is refused at the document and at the ledger
        $svc = new EntryService(App::db());
        $this->assertThrows(DomainRuleException::class, fn () => $svc->post($e, $this->root), 'already been posted');
        App::db()->update('inventory_entries', ['status' => 'approved'], ['id' => $e['id']]);
        $this->assertThrows(DomainRuleException::class, fn () => $svc->post($svc->find((int) $e['id']), $this->root), 'already been posted');
        App::db()->update('inventory_entries', ['status' => 'posted'], ['id' => $e['id']]);
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE source_type = 'opening' AND source_id = ?", [$e['id']]));
        $this->assertSame([], (new InventoryLedger(App::db()))->reconcile());
        // future dates are refused; stable identifiers come from Phase 6
        $this->assertThrows(ValidationException::class, fn () => $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'variant_id' => $v16, 'quantity' => '1']], ['entry_date' => BusinessDate::addDays(BusinessDate::today(), 2)]), 'future');
        $this->assertSame(36, strlen((string) App::db()->scalar('SELECT uuid FROM product_variants WHERE id = ?', [$v16])));
    }

    public function testInwardOutwardNegativeStockAndReversal(): void
    {
        $p = $this->product('Table Lamp');
        $supplier = (new \App\Services\Supplier\SupplierService(App::db()))->create(['name' => 'Lumina', 'supplier_type_id' => (int) App::db()->scalar("SELECT id FROM supplier_types WHERE code = 'distributor'"),
            'gst_treatment' => 'unregistered', 'country' => 'IN'], $this->root, true)[0];
        $in = $this->entry('inward', 'purchase_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '10', 'unit_cost' => '1350']], ['supplier_id' => $supplier, 'source_reference' => 'LUM/INV/77']);
        $this->assertSame('posted', $in['status']);
        $this->assertSame('10', $this->onHand($this->whA, (int) $p['id']));
        $this->assertThrows(ValidationException::class, fn () => $this->entry('inward', 'purchase_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '1']]), 'unit cost');
        // negative stock is refused by default; the document stays approved with the reason
        $svc = new EntryService(App::db());
        $id = $svc->save('outward', ['entry_type' => 'sales_dispatch', 'warehouse_id' => $this->whA, 'entry_date' => BusinessDate::today(), 'source_reference' => 'AMZ-1',
            'lines' => [['product_id' => $p['id'], 'quantity' => '12']]], null, $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $svc->submit($svc->find($id), $this->root), 'free stock');
        $out = $svc->find($id);
        $this->assertSame('approved', $out['status']);
        $this->assertContains('free stock', (string) $out['posting_error']);
        $this->assertSame('10', $this->onHand($this->whA, (int) $p['id']), 'nothing posted');
        $svc->cancel($out, 'Order changed', $this->root);
        $o = $this->entry('outward', 'sales_dispatch', $this->whA, [['product_id' => $p['id'], 'quantity' => '4']], ['source_reference' => 'AMZ-2']);
        $this->assertSame('6', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame('5400.00', Money::toDb(Money::fromDb(App::db()->scalar('SELECT total_cost FROM inventory_entry_items WHERE entry_id = ?', [$o['id']]))));
        // reversal restores stock and value with opposite movements; posted rows stay
        $svc->reverse($svc->find((int) $o['id']), 'Order cancelled after dispatch', $this->root);
        $this->assertSame('10', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame('13500.00', $this->value($this->whA, (int) $p['id']));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE movement_type = 'reversal' AND source_id = ?", [$o['id']]));
        $this->assertThrows(DomainRuleException::class, fn () => $svc->reverse($svc->find((int) $o['id']), 'again', $this->root), 'Only posted');
        // allowing negative stock on the product
        (new ProductInventoryService(App::db()))->save((int) $p['id'], ['allow_negative' => 1], $this->root);
        $this->entry('outward', 'consumption', $this->whA, [['product_id' => $p['id'], 'quantity' => '12']], ['reason' => 'Display units']);
        $this->assertSame('-2', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame('negative', (new StockQueryService(App::db()))->items($this->root, ['product' => $p['id']])[0]['stock_status']);
        $this->assertSame([], (new InventoryLedger(App::db()))->reconcile());
        // the purchase receipt is linkable to its Phase 11 document later, once only
        $svc->linkSource($svc->find((int) $in['id']), 'purchase_receipt', 501, $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $svc->linkSource($svc->find((int) $in['id']), 'purchase_receipt', 502, $this->root), 'already linked');
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions'), 'no bank transactions');
    }

    public function testFifoAndWeightedAverageValuation(): void
    {
        $fifo = $this->product('FIFO Bulb', ['valuation_method' => 'fifo']);
        $wac = $this->product('WAC Bulb', ['valuation_method' => 'weighted_average']);
        foreach ([$fifo, $wac] as $p) {
            $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '10', 'unit_cost' => '100']]);
            $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '10', 'unit_cost' => '130']]);
        }
        $f = $this->entry('outward', 'consumption', $this->whA, [['product_id' => $fifo['id'], 'quantity' => '15']], ['reason' => 'Test']);
        $w = $this->entry('outward', 'consumption', $this->whA, [['product_id' => $wac['id'], 'quantity' => '15']], ['reason' => 'Test']);
        $cost = static fn (array $e): string => Money::toDb(Money::fromDb(App::db()->scalar('SELECT total_cost FROM inventory_entry_items WHERE entry_id = ?', [$e['id']])));
        $this->assertSame('1650.00', $cost($f), 'FIFO: 10 × 100 + 5 × 130');
        $this->assertSame('1725.00', $cost($w), 'WAC: 15 × 115');
        $this->assertSame('650.00', $this->value($this->whA, (int) $fifo['id']));
        $this->assertSame('575.00', $this->value($this->whA, (int) $wac['id']));
        // FIFO layers move with a transfer and stay consistent
        $tr = new TransferService(App::db());
        $id = $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whB, 'transfer_date' => BusinessDate::today(), 'lines' => [['product_id' => $fifo['id'], 'quantity' => '5']]], null, $this->root);
        $tr->submit($tr->find($id), $this->root);
        $tr->receive($tr->find($id), [(int) App::db()->scalar('SELECT id FROM inventory_transfer_items WHERE transfer_id = ?', [$id]) => ['received' => '5']], BusinessDate::today(), '', $this->root);
        $this->assertSame('650.00', $this->value($this->whB, (int) $fifo['id']));
        $this->assertSame('0.00', $this->value($this->whA, (int) $fifo['id']));
        // revaluation needs the valuation-correction approval when configured
        $this->workflow('inventory_valuation_correction');
        $item = App::db()->first('SELECT * FROM inventory_stock_items WHERE item_key = ?', [$this->whA . ':' . $wac['id'] . ':0']);
        $this->assertSame('pending', (new CorrectionService(App::db()))->requestRevaluation($item, '120', 'Supplier credit note', $this->root));
        $this->assertSame('575.00', $this->value($this->whA, (int) $wac['id']), 'not applied before approval');
        $approver = $this->makeUser('approver@example.com', ['super-admin'], name: 'Approver');
        $req = (int) App::db()->scalar("SELECT approval_request_id FROM inventory_change_requests WHERE change_type = 'valuation_correction'");
        App::approvals()->approve($req, $approver, 'OK');
        $this->assertSame('600.00', $this->value($this->whA, (int) $wac['id']));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_valuation_history WHERE event = 'revaluation'"));
        $this->assertSame([], (new InventoryLedger(App::db()))->reconcile());
        // the default method cannot change while stock valued with it is on hand
        $plain = $this->product('Plain item');
        $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $plain['id'], 'quantity' => '1', 'unit_cost' => '10']]);
        $this->assertThrows(ValidationException::class, fn () => (new CorrectionService(App::db()))->setDefaultMethod('fifo', 'policy', $this->root), 'on hand');
    }

    public function testTransfersInTransitPartialReceiptLossAndReversal(): void
    {
        $p = $this->product('Bedsheet Set');
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '20', 'unit_cost' => '700']]);
        $tr = new TransferService(App::db());
        $this->assertThrows(ValidationException::class, fn () => $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whA, 'transfer_date' => BusinessDate::today(),
            'lines' => [['product_id' => $p['id'], 'quantity' => '1']]], null, $this->root), 'location');
        $id = $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whB, 'transfer_date' => BusinessDate::today(), 'reason' => 'Store replenishment',
            'lines' => [['product_id' => $p['id'], 'quantity' => '12']]], null, $this->root);
        $this->workflow('inventory_transfer');
        $t = $tr->submit($tr->find($id), $this->root);
        $this->assertSame('pending_approval', $t['transfer_status']);
        $this->assertSame('20', $this->onHand($this->whA, (int) $p['id']), 'pending transfers do not move stock');
        $approver = $this->makeUser('approver@example.com', ['super-admin'], name: 'Approver');
        $this->assertThrows(\App\Exceptions\AuthorizationException::class, fn () => App::approvals()->approve((int) $t['approval_request_id'], $this->root, 'self'), 'own request');
        App::approvals()->approve((int) $t['approval_request_id'], $approver, 'OK');
        $t = $tr->find($id);
        $this->assertSame('in_transit', $t['transfer_status']);
        $this->assertSame('8', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame('12', $this->onHand($this->whA, (int) $p['id'], null, 'in_transit'));
        $this->assertSame('0', $this->onHand($this->whB, (int) $p['id']));
        $line = (int) App::db()->scalar('SELECT id FROM inventory_transfer_items WHERE transfer_id = ?', [$id]);
        $this->assertThrows(ValidationException::class, fn () => $tr->receive($t, [$line => ['received' => '13']], BusinessDate::today(), '', $this->root), 'still in transit');
        $tr->receive($t, [$line => ['received' => '8', 'damaged' => '1']], BusinessDate::today(), 'First truck', $this->root);
        $t = $tr->find($id);
        $this->assertSame('partially_received', $t['transfer_status']);
        $this->assertSame('8', $this->onHand($this->whB, (int) $p['id']));
        $this->assertSame('1', $this->onHand($this->whB, (int) $p['id'], null, 'damaged'));
        $this->assertSame('3', $this->onHand($this->whA, (int) $p['id'], null, 'in_transit'));
        $tr->writeOffLoss($t, [$line => ['lost' => '3']], 'Carton lost by courier', $this->root);
        $t = $tr->find($id);
        $this->assertSame('received', $t['transfer_status']);
        $this->assertSame('0', $this->onHand($this->whA, (int) $p['id'], null, 'in_transit'));
        // stock moved, nothing bought or sold; every out has a paired in
        $this->assertSame(0, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE source_type = 'transfer' AND movement_type IN ('purchase_receipt', 'sales_dispatch')"));
        $this->assertSame((int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE source_type = 'transfer' AND movement_type = 'transfer_in'"),
            (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE source_type = 'transfer' AND movement_type = 'transfer_in' AND transfer_pair_id IS NOT NULL"));
        $this->assertSame('11900.00', Money::toDb(Money::fromDb(App::db()->scalar("SELECT SUM(stock_value) FROM inventory_stock_items WHERE product_id = ?", [$p['id']]))), '20 × 700 − 3 lost');
        // a transfer with a written-off loss is not reversed as a whole (lost stock must not reappear)
        $this->assertThrows(DomainRuleException::class, fn () => $tr->reverse($t, 'x', $this->root), 'lost in transit');
        // a fully received transfer can be reversed while the destination still holds the stock
        $id2 = $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whB, 'transfer_date' => BusinessDate::today(), 'lines' => [['product_id' => $p['id'], 'quantity' => '2']]], null, $this->root);
        $t2 = $tr->submit($tr->find($id2), $this->root);
        App::approvals()->approve((int) $t2['approval_request_id'], $approver, 'OK');
        $tr->receive($tr->find($id2), [(int) App::db()->scalar('SELECT id FROM inventory_transfer_items WHERE transfer_id = ?', [$id2]) => ['received' => '2']], BusinessDate::today(), '', $this->root);
        $this->assertSame(['6', '10'], [$this->onHand($this->whA, (int) $p['id']), $this->onHand($this->whB, (int) $p['id'])]);
        $tr->reverse($tr->find($id2), 'Sent to the wrong store', $this->root);
        $this->assertSame('reversed', $tr->find($id2)['transfer_status']);
        $this->assertSame(['8', '8'], [$this->onHand($this->whA, (int) $p['id']), $this->onHand($this->whB, (int) $p['id'])]);
        $this->assertSame('0', $this->onHand($this->whA, (int) $p['id'], null, 'in_transit'));
        $this->assertSame([], (new InventoryLedger(App::db()))->reconcile());
        // moves between locations of one warehouse never go in transit
        $locs = new LocationService(App::db());
        $a = (new WarehouseService(App::db()))->find($this->whA);
        $l1 = $locs->save($a, ['code' => 'L1', 'name' => 'Rack 1', 'location_type' => 'rack'], null, $this->root);
        $l2 = $locs->save($a, ['code' => 'L2', 'name' => 'Rack 2', 'location_type' => 'rack'], null, $this->root);
        $q = $this->product('Brass Diya', ['valuation_method' => 'fifo']);
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $q['id'], 'quantity' => '6', 'unit_cost' => '50', 'location_id' => $l1]]);
        $this->assertThrows(ValidationException::class, fn () => $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whA, 'transfer_date' => BusinessDate::today(),
            'lines' => [['product_id' => $q['id'], 'quantity' => '1', 'from_location_id' => $l1, 'to_location_id' => $l1]]], null, $this->root), 'same location');
        $mid = $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whA, 'transfer_date' => BusinessDate::today(),
            'lines' => [['product_id' => $q['id'], 'quantity' => '4', 'from_location_id' => $l1, 'to_location_id' => $l2]]], null, $this->root);
        $m = $tr->submit($tr->find($mid), $this->root);
        App::approvals()->approve((int) $m['approval_request_id'], $approver, 'OK');
        $this->assertSame('received', $tr->find($mid)['transfer_status']);
        $this->assertSame('300.00', $this->value($this->whA, (int) $q['id']), 'value unchanged by a location move');
        $this->assertSame([], (new InventoryLedger(App::db()))->reconcile(), 'FIFO layers intact after a location move');
    }

    public function testAdjustmentsCountsConditionChangesAndRecount(): void
    {
        $p = $this->product('Ceramic Bowl');
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '30', 'unit_cost' => '80']]);
        $adj = new AdjustmentService(App::db());
        $id = $adj->save(['warehouse_id' => $this->whA, 'adjustment_date' => BusinessDate::today(), 'reason' => 'physical_difference',
            'lines' => [['line_type' => 'count', 'product_id' => $p['id'], 'physical_qty' => '27']]], null, $this->root);
        $line = App::db()->first('SELECT * FROM inventory_adjustment_items WHERE adjustment_id = ?', [$id]);
        $this->assertSame('30', Qty::format(Qty::fromDb($line['system_qty'])));
        $this->assertSame('-3', Qty::format(Qty::fromDb($line['difference_qty'])));
        // stock moved after counting → recount required
        $this->entry('outward', 'consumption', $this->whA, [['product_id' => $p['id'], 'quantity' => '1']], ['reason' => 'Sample']);
        $this->assertThrows(DomainRuleException::class, fn () => $adj->submit($adj->find($id), $this->root), 'recount');
        $a = $adj->find($id);
        $this->assertSame('approved', $a['status']);
        $adj->cancel($a, 'Recount', $this->root);
        $id = $adj->save(['warehouse_id' => $this->whA, 'adjustment_date' => BusinessDate::today(), 'reason' => 'physical_difference',
            'lines' => [['line_type' => 'count', 'product_id' => $p['id'], 'physical_qty' => '27'], ['line_type' => 'condition', 'product_id' => $p['id'], 'quantity' => '2', 'to_state' => 'damaged']]], null, $this->root);
        $adj->submit($adj->find($id), $this->root);
        $this->assertSame('posted', $adj->find($id)['status']);
        $this->assertSame('25', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame('2', $this->onHand($this->whA, (int) $p['id'], null, 'damaged'));
        $this->assertSame('2160.00', $this->value($this->whA, (int) $p['id']), '27 × 80; a condition change keeps the value');
        // reversal posts opposite movements and restores the counted and moved stock
        $adj->reverse($adj->find($id), 'Count was wrong', $this->root);
        $this->assertSame('29', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame('0', $this->onHand($this->whA, (int) $p['id'], null, 'damaged'));
        $this->assertSame(0, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements m WHERE m.source_type = 'adjustment' AND m.source_id = ? AND m.movement_type <> 'reversal'
            AND NOT EXISTS (SELECT 1 FROM inventory_stock_movements r WHERE r.reversal_of_id = m.id)", [$id]), 'every movement reversed');
        // damaged stock is written off from the damaged condition; then the move can no longer be reversed
        $id2 = $adj->save(['warehouse_id' => $this->whA, 'adjustment_date' => BusinessDate::today(), 'reason' => 'damaged',
            'lines' => [['line_type' => 'condition', 'product_id' => $p['id'], 'quantity' => '2', 'to_state' => 'damaged']]], null, $this->root);
        $adj->submit($adj->find($id2), $this->root);
        $this->entry('outward', 'damaged', $this->whA, [['product_id' => $p['id'], 'quantity' => '2', 'stock_state' => 'damaged']], ['reason' => 'Broken in handling']);
        $this->assertSame('0', $this->onHand($this->whA, (int) $p['id'], null, 'damaged'));
        $this->assertThrows(DomainRuleException::class, fn () => $adj->reverse($adj->find($id2), 'x', $this->root), 'still there');
        $this->assertSame('posted', $adj->find($id2)['status'], 'a failed reversal changes nothing');
        $this->assertSame([], (new InventoryLedger(App::db()))->reconcile());
    }

    public function testBatchesExpiryFefoAndCorrections(): void
    {
        $p = $this->product('Herbal Soap', ['batch_tracking' => 1, 'expiry_tracking' => 1]);
        $this->assertThrows(ValidationException::class, fn () => $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '5', 'batch_no' => 'B1']]), 'expiry date');
        $today = BusinessDate::today();
        $this->entry('inward', 'other_receipt', $this->whA, [
            ['product_id' => $p['id'], 'quantity' => '5', 'batch_no' => 'OLD', 'expiry_date' => BusinessDate::addDays($today, -3), 'unit_cost' => '20'],
            ['product_id' => $p['id'], 'quantity' => '10', 'batch_no' => 'B-JUN', 'expiry_date' => BusinessDate::addDays($today, 60), 'unit_cost' => '22'],
            ['product_id' => $p['id'], 'quantity' => '10', 'batch_no' => 'B-MAY', 'expiry_date' => BusinessDate::addDays($today, 20), 'mfg_date' => BusinessDate::addDays($today, -100), 'unit_cost' => '21'],
        ]);
        $bs = new BatchSerialService(App::db());
        $this->assertSame('expired', BatchSerialService::expiryStatus(BusinessDate::addDays($today, -3)));
        $this->assertSame('near_expiry', BatchSerialService::expiryStatus(BusinessDate::addDays($today, 20)));
        $this->assertSame(['OLD'], array_column($bs->batches($this->root, ['expiry' => 'expired']), 'batch_no'));
        // expired stock is not issued normally; FEFO picks the earliest expiry that is still valid
        $e = $this->entry('outward', 'consumption', $this->whA, [['product_id' => $p['id'], 'quantity' => '12']], ['reason' => 'Hotel amenities']);
        $batches = App::db()->select('SELECT b.batch_no, m.qty_out FROM inventory_stock_movements m JOIN inventory_batches b ON b.id = m.batch_id WHERE m.source_id = ? AND m.source_type = ? ORDER BY m.id', [$e['id'], 'outward']);
        $this->assertSame(['B-MAY', 'B-JUN'], array_column($batches, 'batch_no'));
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry('outward', 'consumption', $this->whA, [['product_id' => $p['id'], 'quantity' => '9']], ['reason' => 'x']), 'expired');
        // written off as expired stock
        $old = (int) App::db()->scalar("SELECT id FROM inventory_batches WHERE batch_no = 'OLD'");
        $this->entry('outward', 'expired', $this->whA, [['product_id' => $p['id'], 'quantity' => '5', 'batch_id' => $old]], ['reason' => 'Expired']);
        $this->assertSame('8', $this->onHand($this->whA, (int) $p['id']));
        // same batch, different expiry → refused
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '1', 'batch_no' => 'B-JUN', 'expiry_date' => BusinessDate::addDays($today, 61)]]), 'already recorded');
        // corrections of a batch with history go through approval
        $this->workflow('inventory_batch_correction');
        $jun = $bs->batch((int) App::db()->scalar("SELECT id FROM inventory_batches WHERE batch_no = 'B-JUN'"));
        $this->assertSame('pending', (new CorrectionService(App::db()))->requestBatchCorrection($jun, ['batch_no' => 'B-JUN', 'expiry_date' => BusinessDate::addDays($today, 90), 'mfg_date' => ''], 'Label misprint', $this->root));
        $approver = $this->makeUser('approver@example.com', ['super-admin'], name: 'Approver');
        App::approvals()->approve((int) App::db()->scalar('SELECT approval_request_id FROM inventory_change_requests'), $approver, 'OK');
        $this->assertSame(BusinessDate::addDays($today, 90), $bs->batch((int) $jun['id'])['expiry_date']);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_history WHERE subject_type = 'batch' AND event = 'corrected'"));
    }

    public function testSerialNumbersAreUniqueAndFollowTheStock(): void
    {
        $p = $this->product('Smart Speaker', ['serial_tracking' => 1]);
        $this->assertThrows(ValidationException::class, fn () => $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '2', 'serial_numbers' => "SN1"]]), 'exactly 2');
        $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '3', 'serial_numbers' => "SN-001\nsn-002\nSN-003", 'unit_cost' => '2500']]);
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '1', 'serial_numbers' => 'SN-002', 'unit_cost' => '1']]), 'already in stock');
        $tr = new TransferService(App::db());
        $id = $tr->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whB, 'transfer_date' => BusinessDate::today(), 'lines' => [['product_id' => $p['id'], 'quantity' => '1', 'serial_numbers' => 'SN-003']]], null, $this->root);
        $tr->submit($tr->find($id), $this->root);
        $sn3 = App::db()->first("SELECT * FROM inventory_serial_numbers WHERE serial_no = 'SN-003'");
        $this->assertSame('in_transit', $sn3['status']);
        $tr->receive($tr->find($id), [(int) App::db()->scalar('SELECT id FROM inventory_transfer_items WHERE transfer_id = ?', [$id]) => ['received' => '1', 'serial_numbers' => 'SN-003']], BusinessDate::today(), '', $this->root);
        $sn3 = App::db()->first("SELECT * FROM inventory_serial_numbers WHERE serial_no = 'SN-003'");
        $this->assertSame([$this->whB, 'in_stock'], [(int) $sn3['warehouse_id'], $sn3['status']]);
        // only serials in stock at the warehouse can be dispatched
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry('outward', 'sales_dispatch', $this->whA, [['product_id' => $p['id'], 'quantity' => '1', 'serial_numbers' => 'SN-003']]), 'not available');
        $this->entry('outward', 'sales_dispatch', $this->whA, [['product_id' => $p['id'], 'quantity' => '1', 'serial_numbers' => 'SN-001']], ['source_reference' => 'ORD-9']);
        $this->assertSame('dispatched', App::db()->scalar("SELECT status FROM inventory_serial_numbers WHERE serial_no = 'SN-001'"));
        $hist = (new BatchSerialService(App::db()))->serialHistory((int) $sn3['id']);
        $this->assertSame(['other_receipt', 'transit_out', 'transit_in', 'transfer_out', 'transfer_in'], array_column($hist, 'movement_type'));
        // a dispatched serial can come back as a customer return (classified, not automatically sellable)
        $this->entry('inward', 'customer_return', $this->whA, [['product_id' => $p['id'], 'quantity' => '1', 'serial_numbers' => 'SN-001', 'return_condition' => 'inspection']], ['source_reference' => 'RMA-1']);
        $sn1 = App::db()->first("SELECT * FROM inventory_serial_numbers WHERE serial_no = 'SN-001'");
        $this->assertSame(['in_stock', 'quarantine'], [$sn1['status'], $sn1['stock_state']]);
        $this->assertSame('1', $this->onHand($this->whA, (int) $p['id']), 'returned unit is not counted as available');
        // corrections of a serial need approval when configured
        $this->assertSame('applied', (new CorrectionService(App::db()))->requestSerialCorrection(App::db()->first("SELECT * FROM inventory_serial_numbers WHERE serial_no = 'SN-002'"), 'SN-002A', 'Typo', $this->root));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_serial_numbers WHERE serial_no = 'SN-002A'"));
    }

    public function testReservationsAvailabilityAndFulfilment(): void
    {
        $p = $this->product('Gift Hamper');
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '10', 'unit_cost' => '500']]);
        $res = new ReservationService(App::db());
        $rid = $res->reserve(['reference_type' => 'sales_order', 'reference_no' => 'SO-101', 'warehouse_id' => $this->whA, 'lines' => [['product_id' => $p['id'], 'quantity' => '7']]], $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $res->reserve(['reference_type' => 'internal', 'reference_no' => 'Expo', 'warehouse_id' => $this->whA, 'lines' => [['product_id' => $p['id'], 'quantity' => '4']]], $this->root), 'free to reserve');
        $this->assertThrows(DomainRuleException::class, fn () => $res->reserve(['reference_type' => 'sales_order', 'reference_no' => 'so-101', 'warehouse_id' => $this->whA, 'lines' => [['product_id' => $p['id'], 'quantity' => '1']]], $this->root), 'already reserved');
        $row = (new StockQueryService(App::db()))->items($this->root, ['product' => $p['id']])[0];
        $this->assertSame(['10', '7', '3'], [Qty::format($row['sellable']), Qty::format($row['reserved']), Qty::format($row['available'])]);
        // an issue for another reference cannot take reserved stock
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry('outward', 'consumption', $this->whA, [['product_id' => $p['id'], 'quantity' => '4']], ['reason' => 'x']), 'reserved for other references');
        // the dispatch that names the reservation fulfils it
        $ri = (int) App::db()->scalar('SELECT id FROM inventory_reservation_items WHERE reservation_id = ?', [$rid]);
        $this->entry('outward', 'sales_dispatch', $this->whA, [['product_id' => $p['id'], 'quantity' => '5', 'reservation_item_id' => $ri]], ['source_reference' => 'SO-101']);
        $this->assertSame('partially_fulfilled', $res->find($rid)['status']);
        $res->close($res->find($rid), 'released', 'Customer reduced the order', $this->root);
        $this->assertSame('released', $res->find($rid)['status']);
        $row = (new StockQueryService(App::db()))->items($this->root, ['product' => $p['id']])[0];
        $this->assertSame(['5', '0', '5'], [Qty::format($row['sellable']), Qty::format($row['reserved']), Qty::format($row['available'])]);
        // expiry releases automatically; a cancelled reference releases everything
        $r2 = $res->reserve(['reference_type' => 'internal', 'reference_no' => 'Expo', 'warehouse_id' => $this->whA, 'reservation_date' => BusinessDate::addDays(BusinessDate::today(), -10),
            'expires_on' => BusinessDate::addDays(BusinessDate::today(), -1), 'lines' => [['product_id' => $p['id'], 'quantity' => '2']]], $this->root);
        $res->expireDue();
        $this->assertSame('expired', $res->find($r2)['status']);
        $res->reserve(['reference_type' => 'sales_order', 'reference_no' => 'SO-102', 'warehouse_id' => $this->whA, 'lines' => [['product_id' => $p['id'], 'quantity' => '2']]], $this->root);
        $this->assertSame(1, $res->releaseReference('sales_order', 'SO-102', 'Order cancelled', $this->root));
    }

    public function testLowStockReorderLevelsAndSuggestions(): void
    {
        $p = $this->product('Linen Napkin', ['reorder_level' => '20', 'min_qty' => '10', 'max_qty' => '100', 'reorder_qty' => '50']);
        $q = $this->product('Jute Runner');
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '15', 'unit_cost' => '30'], ['product_id' => $q['id'], 'quantity' => '40', 'unit_cost' => '90']]);
        (new ProductInventoryService(App::db()))->saveRule((int) $q['id'], ['warehouse_id' => $this->whA, 'reorder_level' => '50', 'max_qty' => '80'], $this->root);
        $sq = new StockQueryService(App::db());
        $status = array_column($sq->items($this->root), 'stock_status', 'product_name');
        $this->assertSame(['Jute Runner' => 'low', 'Linen Napkin' => 'low'], $status);
        $sug = array_column($sq->reorderSuggestions($this->root), 'suggested_qty', 'product_name');
        $this->assertSame('85', Qty::format($sug['Linen Napkin']), 'up to max 100');
        $this->assertSame('40', Qty::format($sug['Jute Runner']), 'warehouse rule: up to 80');
        $never = $this->product('Brass Bell');
        $out = array_column($sq->products($this->root, ['status' => 'out']), 'product_name');
        $this->assertTrue(in_array('Brass Bell', $out, true), 'never-received products are out of stock');
        $this->assertSame(0, (int) App::db()->scalar("SELECT COUNT(*) FROM approval_requests WHERE title LIKE '%purchase%'"), 'no purchase orders created');
        $t = $sq->totals($this->root);
        $this->assertSame(2, $t['low']);
        $this->assertSame('4050.00', Money::toDb($t['value']), '15 × 30 + 40 × 90');
    }

    public function testWarehouseScopeAssignmentsAndPermissions(): void
    {
        $p = $this->product('Copper Bottle');
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '10', 'unit_cost' => '300']]);
        $this->entry('opening', 'opening', $this->whB, [['product_id' => $p['id'], 'quantity' => '4', 'unit_cost' => '300']]);
        $mgr = $this->makeUser('wm@example.com', [], name: 'Warehouse Manager A');
        $tpl = (int) App::db()->scalar("SELECT id FROM permission_templates WHERE slug = 'inventory-manager'");
        $role = App::db()->insert('roles', ['name' => 'WH Mgr', 'slug' => 'wh-mgr', 'is_system' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach (App::db()->select('SELECT permission_id FROM permission_template_items WHERE template_id = ?', [$tpl]) as $i) {
            App::db()->insert('role_permissions', ['role_id' => $role, 'permission_id' => $i['permission_id'], 'data_scope' => 'assigned', 'created_at' => now(), 'updated_at' => now()]);
        }
        App::db()->insert('user_roles', ['user_id' => $mgr['id'], 'role_id' => $role, 'assigned_at' => now()]);
        App::flushAuthorization();
        $this->assertSame([], InventoryScope::warehouseIds($mgr), 'nothing assigned yet');
        (new WarehouseService(App::db()))->setAssignment($this->whA, (int) $mgr['id'], true, $this->root);
        $this->assertSame([$this->whA], InventoryScope::warehouseIds($mgr));
        $this->assertSame([$this->whA], array_map('intval', array_column((new StockQueryService(App::db()))->items($mgr), 'warehouse_id')));
        $this->assertTrue(App::gate()->allows($mgr, 'inventory.manage_stock_inward'));
        $this->assertFalse(App::gate()->allows($mgr, 'inventory.manage_opening_stock'));
        $this->assertThrows(ValidationException::class, fn () => (new TransferService(App::db()))->save(['from_warehouse_id' => $this->whA, 'to_warehouse_id' => $this->whB, 'transfer_date' => BusinessDate::today(),
            'lines' => [['product_id' => $p['id'], 'quantity' => '1']]], null, $mgr), 'access');
        // stock operator template: may record inward, may not approve or see costs
        $op = $this->makeUser('op@example.com', [], name: 'Stock Operator');
        $tpl2 = (int) App::db()->scalar("SELECT id FROM permission_templates WHERE slug = 'stock-operator'");
        $role2 = App::db()->insert('roles', ['name' => 'Op', 'slug' => 'op', 'is_system' => 0, 'created_at' => now(), 'updated_at' => now()]);
        foreach (App::db()->select('SELECT permission_id FROM permission_template_items WHERE template_id = ?', [$tpl2]) as $i) {
            App::db()->insert('role_permissions', ['role_id' => $role2, 'permission_id' => $i['permission_id'], 'data_scope' => 'all', 'created_at' => now(), 'updated_at' => now()]);
        }
        App::db()->insert('user_roles', ['user_id' => $op['id'], 'role_id' => $role2, 'assigned_at' => now()]);
        App::flushAuthorization();
        $this->assertTrue(App::gate()->allows($op, 'inventory.manage_stock_inward'));
        $this->assertFalse(App::gate()->allows($op, 'inventory.approve'));
        $this->assertFalse(App::gate()->allows($op, 'inventory.view_stock_cost'));
        $this->workflow('inventory_inward');
        $e = $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '2', 'unit_cost' => '300']], [], $op);
        $this->assertSame('pending_approval', $e['status']);
        $this->assertThrows(\App\Exceptions\AuthorizationException::class, fn () => App::approvals()->approve((int) $e['approval_request_id'], $op, 'self'));
        $this->assertSame('10', $this->onHand($this->whA, (int) $p['id']), 'pending inward does not affect stock');
        App::approvals()->approve((int) $e['approval_request_id'], $this->root, 'OK');
        $this->assertSame('12', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame((int) $this->root['id'], (int) App::db()->scalar('SELECT approved_by FROM inventory_stock_movements WHERE source_type = ? AND source_id = ?', ['inward', $e['id']]));
        // rejection leaves stock untouched and keeps the reason
        $e2 = $this->entry('inward', 'other_receipt', $this->whA, [['product_id' => $p['id'], 'quantity' => '2', 'unit_cost' => '300']], [], $op);
        App::approvals()->reject((int) $e2['approval_request_id'], $this->root, 'No delivery note');
        $this->assertSame(['rejected', 'No delivery note'], [(new EntryService(App::db()))->find((int) $e2['id'])['status'], (new EntryService(App::db()))->find((int) $e2['id'])['rejection_reason']]);
        $this->assertSame('12', $this->onHand($this->whA, (int) $p['id']));
        // overrides need their own permission
        $this->assertThrows(ValidationException::class, fn () => (new EntryService(App::db()))->save('outward', ['entry_type' => 'consumption', 'warehouse_id' => $this->whA, 'entry_date' => BusinessDate::today(),
            'reason' => 'x', 'negative_override' => 1, 'lines' => [['product_id' => $p['id'], 'quantity' => '50']]], null, $op), 'overrides');
    }

    public function testConcurrentIssuesCannotOversell(): void
    {
        $p = $this->product('Last Units');
        $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '10', 'unit_cost' => '10']]);
        $svc = new EntryService(App::db());
        $ids = [];
        foreach ([1, 2] as $n) {
            $ids[] = $svc->save('outward', ['entry_type' => 'consumption', 'warehouse_id' => $this->whA, 'entry_date' => BusinessDate::today(), 'reason' => 'race ' . $n,
                'lines' => [['product_id' => $p['id'], 'quantity' => '7']]], null, $this->root);
            App::db()->update('inventory_entries', ['status' => 'approved', 'approved_by' => $this->root['id']], ['id' => end($ids)]);
        }
        // two separate processes post at the same moment against the same database file
        $script = sys_get_temp_dir() . '/inventory-race-' . getmypid() . '.php';
        file_put_contents($script, '<?php require ' . var_export(App::basePath('vendor/autoload.php'), true) . '; App\Core\App::boot(' . var_export(App::basePath(), true) . ');
            $db = App\Core\App::db(); $db->pdo()->setAttribute(PDO::ATTR_TIMEOUT, 10); $db->pdo()->exec("PRAGMA busy_timeout = 10000");
            $s = new App\Services\Inventory\EntryService($db);
            usleep(200000);
            try { $s->post($s->find((int) $argv[1]), ["id" => (int) $argv[2]]); echo "posted"; } catch (Throwable $e) { echo "refused: " . $e->getMessage(); }');
        $procs = [];
        foreach ($ids as $id) {
            $procs[] = proc_open([PHP_BINARY, $script, (string) $id, (string) $this->root['id']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
            $outs[] = $pipes;
        }
        $results = [];
        foreach ($procs as $i => $proc) {
            $results[] = stream_get_contents($outs[$i][1]) . stream_get_contents($outs[$i][2]);
            proc_close($proc);
        }
        @unlink($script);
        $posted = count(array_filter($results, static fn (string $r): bool => str_starts_with($r, 'posted')));
        $this->assertSame(1, $posted, 'exactly one of two simultaneous issues of 7 from 10 succeeds: ' . implode(' | ', $results));
        $this->assertSame('3', $this->onHand($this->whA, (int) $p['id']));
        $this->assertSame([], (new InventoryLedger(App::db()))->reconcile());
    }

    public function testLedgerIsImmutableTraceableAndReconciles(): void
    {
        $p = $this->product('Audit Item');
        $e = $this->entry('opening', 'opening', $this->whA, [['product_id' => $p['id'], 'quantity' => '3', 'unit_cost' => '10']]);
        $m = App::db()->first('SELECT * FROM inventory_stock_movements WHERE source_id = ?', [$e['id']]);
        foreach (['movement_no', 'movement_date', 'product_id', 'warehouse_id', 'movement_type', 'qty_in', 'unit_cost', 'total_cost', 'source_type', 'source_id', 'reference_no', 'posting_key', 'created_by', 'approved_by', 'posted_at'] as $k) {
            $this->assertTrue($m[$k] !== null && $m[$k] !== '', "ledger field {$k}");
        }
        $this->assertSame(0, (int) App::db()->scalar("SELECT COUNT(*) FROM inventory_stock_movements WHERE source_type = '' OR source_id IS NULL OR posting_key IS NULL"));
        // a tampered summary is detected and rebuilt from the ledger
        App::db()->update('inventory_stock_balances', ['quantity' => '99.0000'], ['product_id' => $p['id']]);
        $ledger = new InventoryLedger(App::db());
        $this->assertSame('balance', $ledger->reconcile()[0]['kind']);
        $ledger->reconcile(true);
        $this->assertSame([], $ledger->reconcile());
        $this->assertSame('3', $this->onHand($this->whA, (int) $p['id']));
        // activity log and history are written for every step
        foreach (['inventory.opening_created', 'inventory.opening_submitted', 'inventory.opening_posted'] as $a) {
            $this->assertTrue((bool) App::db()->scalar('SELECT 1 FROM activity_logs WHERE action = ?', [$a]), $a);
        }
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM inventory_history WHERE subject_type = 'entry' AND event = 'posted'"));
        // a ledger row cannot be posted outside a transaction
        $this->assertThrows(\LogicException::class, fn () => $ledger->post(['item' => $ledger->item($this->whA, (int) $p['id'], null), 'dir' => 'in', 'type' => 'other_receipt', 'qty' => 10000,
            'source_type' => 't', 'source_id' => 1, 'posting_key' => 'x', 'reference_no' => 'x', 'date' => BusinessDate::today(), 'actor' => $this->root]));
    }
}
