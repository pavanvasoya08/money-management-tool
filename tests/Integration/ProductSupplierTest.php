<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Admin\WorkflowService;
use App\Services\Product\CatalogService;
use App\Services\Product\DatedMappingService;
use App\Services\Product\PricingService;
use App\Services\Product\ProductMediaService;
use App\Services\Product\ProductReportService;
use App\Services\Product\ProductService;
use App\Services\Product\SkuService;
use App\Services\Product\SupplierMappingService;
use App\Services\Product\TaxConfigService;
use App\Services\Product\VariantService;
use App\Services\Supplier\SupplierBankService;
use App\Services\Supplier\SupplierDetailService;
use App\Services\Supplier\SupplierReportService;
use App\Services\Supplier\SupplierService;
use App\Support\BusinessDate;
use App\Support\Uuid;
use Tests\TestCase;

/** Phase 6: products, catalogue masters, variants, SKUs, prices, GST/HSN, suppliers and mappings at the service layer. */
final class ProductSupplierTest extends TestCase
{
    private array $root;
    private ProductService $products;
    private CatalogService $catalog;
    private SupplierService $suppliers;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $this->products = new ProductService(App::db());
        $this->catalog = new CatalogService(App::db());
        $this->suppliers = new SupplierService(App::db());
    }

    private function setting(string $key, mixed $value): void
    {
        $input = [];
        foreach (\App\Services\SettingsService::definitions() as $k => $d) {
            if ($d['group'] === 'products') {
                $v = App::settings()->get($k);
                $input[$k] = is_bool($v) ? ($v ? '1' : '0') : $v;
            }
        }
        $input[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        App::settings()->update($input, (int) $this->root['id'], ['products']);
    }

    private function type(string $code): int
    {
        return (int) App::db()->scalar('SELECT id FROM product_types WHERE code = ?', [$code]);
    }

    private function enableWorkflow(string $key): void
    {
        $wf = (int) App::db()->scalar('SELECT id FROM approval_workflows WHERE workflow_key = ?', [$key]);
        (new WorkflowService(App::db()))->saveSteps($wf, [['name' => 'Review', 'approver_type' => 'permission', 'min_amount' => '0']], $this->root);
        (new WorkflowService(App::db()))->update($wf, true, null, $this->root);
    }

    /** @param array<string, mixed> $extra */
    private function product(string $name, array $extra = [], bool $activate = true): array
    {
        [$id] = $this->products->create($extra + ['name' => $name, 'product_type_id' => $this->type('physical'), 'unit' => 'pcs'], $this->root, $activate);
        return $this->products->find($id);
    }

    /** @param array<string, mixed> $extra */
    private function supplier(string $name, string $gstin, array $extra = []): array
    {
        [$id] = $this->suppliers->create($extra + ['name' => $name, 'supplier_type_id' => (int) App::db()->scalar("SELECT id FROM supplier_types WHERE code = 'manufacturer'"),
            'gst_treatment' => 'registered', 'gstin' => $gstin, 'country' => 'IN', 'mobile' => '9876543210', 'email' => strtolower(str_replace(' ', '', $name)) . '@example.com'], $this->root, true);
        return $this->suppliers->find($id);
    }

    private function gstReg(string $gstin, string $state): int
    {
        return (new \App\Services\Gst\GstRegistrationService(App::db()))->create(['gstin' => $gstin, 'legal_name' => 'Mehta Traders',
            'registration_type_id' => (int) App::db()->scalar("SELECT id FROM gst_registration_types WHERE code = 'regular'"), 'status' => 'active', 'addr_line1' => '1 Road',
            'addr_city' => 'City', 'addr_state_id' => (int) App::db()->scalar('SELECT id FROM gst_states WHERE code = ?', [$state]), 'addr_pin_code' => '380001'], $this->root)[0];
    }

    public function testCategoriesNestWithoutLoopsAndBrandsAreManaged(): void
    {
        $elec = $this->catalog->saveCategory(['code' => 'ELEC', 'name' => 'Electronics'], $this->root);
        $audio = $this->catalog->saveCategory(['code' => 'AUD', 'name' => 'Audio', 'parent_id' => $elec], $this->root);
        $head = $this->catalog->saveCategory(['code' => 'HEAD', 'name' => 'Headphones', 'parent_id' => $audio], $this->root);
        $this->catalog->saveCategory(['code' => 'HOME', 'name' => 'Home'], $this->root);
        $this->assertSame([$audio, $head], $this->catalog->descendantIds($elec));
        $this->assertSame('Electronics › Audio › Headphones', $this->catalog->categoryPath($head));
        $this->assertThrows(ValidationException::class, fn () => $this->catalog->saveCategory(['code' => 'ELEC', 'name' => 'Electronics', 'parent_id' => $head], $this->root, $elec), 'under itself');
        $this->assertThrows(ValidationException::class, fn () => $this->catalog->saveCategory(['code' => 'AUD', 'name' => 'Audio', 'parent_id' => $audio], $this->root, $audio), 'under itself');
        $this->assertThrows(ValidationException::class, fn () => $this->catalog->saveCategory(['code' => 'elec', 'name' => 'Dup'], $this->root), 'already used');
        $this->assertSame(4, count($this->catalog->categoryTree()));
        // Unlimited (bounded) depth
        $parent = $head;
        for ($i = 1; $i <= 7; $i++) {
            $parent = $this->catalog->saveCategory(['code' => 'L' . $i, 'name' => 'Level ' . $i, 'parent_id' => $parent], $this->root);
        }
        $this->assertThrows(ValidationException::class, fn () => $this->catalog->saveCategory(['code' => 'L8', 'name' => 'Too deep', 'parent_id' => $parent], $this->root), 'at most');

        $b1 = $this->catalog->saveBrand(['code' => 'SONIQ', 'name' => 'Soniq', 'website' => 'https://soniq.example'], $this->root);
        $this->catalog->saveBrand(['code' => 'ACME', 'name' => 'Acme'], $this->root);
        $this->assertThrows(ValidationException::class, fn () => $this->catalog->saveBrand(['code' => 'X1', 'name' => 'Bad', 'website' => 'javascript:alert(1)'], $this->root), 'https');
        $p = $this->product('Wireless headphones', ['category_id' => $elec, 'subcategory_id' => $head, 'brand_id' => $b1]);
        // Archiving keeps the reference; archived masters cannot be chosen for other products.
        $this->assertThrows(DomainRuleException::class, fn () => $this->catalog->setMasterStatus('category', $elec, 'archive', 'Old', $this->root), 'sub-categories');
        $this->catalog->setMasterStatus('brand', $b1, 'archive', 'Brand retired', $this->root);
        $this->assertSame((int) $b1, (int) $this->products->find((int) $p['id'])['brand_id'], 'product keeps its brand');
        $this->assertThrows(ValidationException::class, fn () => $this->product('Other', ['brand_id' => $b1]), 'archived');
        $this->assertThrows(ValidationException::class, fn () => $this->product('Bad sub', ['category_id' => (int) App::db()->scalar("SELECT id FROM product_categories WHERE code = 'HOME'"), 'subcategory_id' => $head]), 'under the chosen category');
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'product.brand_archived'"));
    }

    public function testProductsWithSkusGeneratedFromPatternAndNeverReused(): void
    {
        $cat = $this->catalog->saveCategory(['code' => 'KIT', 'name' => 'Kitchen'], $this->root);
        $a = $this->product('Steel bottle', ['category_id' => $cat]);
        $b = $this->product('Glass bottle', ['category_id' => $cat]);
        $c = $this->product('Plain item');
        $this->assertSame('KIT-00001', $a['sku']);
        $this->assertSame('KIT-00002', $b['sku']);
        $this->assertSame('GEN-00001', $c['sku'], 'each prefix has its own running number');
        $this->assertTrue(Uuid::isValid($a['uuid']) && $a['uuid'] !== $b['uuid'], 'stable unique identifiers');
        $this->assertSame('active', $a['status']);
        $m = $this->product('Manual SKU item', ['sku' => 'kit-00003 ']);
        $this->assertSame('KIT-00003', $m['sku'], 'typed SKUs are normalised');
        $d = $this->product('Next generated', ['category_id' => $cat]);
        $this->assertSame('KIT-00004', $d['sku'], 'numbers already taken by typed SKUs are skipped');
        $this->assertThrows(ValidationException::class, fn () => $this->product('Dup', ['sku' => 'KIT-00001']), 'already used');
        // Change a SKU: the old one stays reserved.
        $this->products->requestSku($a, null, 'BOTTLE-STEEL-750', 'Rebranding', $this->root);
        $this->assertSame('BOTTLE-STEEL-750', $this->products->find((int) $a['id'])['sku']);
        $this->assertThrows(ValidationException::class, fn () => $this->product('Reuse', ['sku' => 'KIT-00001']), 'stays reserved');
        $this->assertSame(2, count((new SkuService(App::db()))->historyFor((int) $a['id'])));
        // Manual SKUs can be switched off.
        $this->setting('product_sku_manual_allowed', false);
        $this->assertThrows(ValidationException::class, fn () => $this->product('No manual', ['sku' => 'ANY-1']), 'automatically');
        $this->setting('product_sku_auto_generate', false);
        $this->assertNull($this->product('No SKU at all')['sku']);
        $this->setting('product_sku_pattern', '{BRAND}-{YY}-{SEQ:3}');
        $this->setting('product_sku_auto_generate', true);
        $this->assertSame('NB-' . date('y') . '-001', $this->product('Pattern item')['sku']);
        $this->assertThrows(ValidationException::class, fn () => $this->setting('product_sku_pattern', '{CAT}-FIXED'), null);
        // Barcodes: GS1 check digit and uniqueness
        $this->assertThrows(ValidationException::class, fn () => $this->product('Bad barcode', ['barcode' => '8901234567892']), 'check digit');
        $this->product('Good barcode', ['barcode' => '8901234567890']);
        $this->assertThrows(ValidationException::class, fn () => $this->product('Same barcode', ['barcode' => '8901234567890']), 'already used');
    }

    public function testVariantsWithFlexibleAttributesAndOwnSkus(): void
    {
        $size = (int) App::db()->scalar("SELECT id FROM product_attributes WHERE code = 'size'");
        $color = (int) App::db()->scalar("SELECT id FROM product_attributes WHERE code = 'color'");
        $weight = (int) App::db()->scalar("SELECT id FROM product_attributes WHERE code = 'weight'");
        $m = $this->catalog->saveAttributeValue($size, 'M', 1, true, $this->root);
        $l = $this->catalog->saveAttributeValue($size, 'L', 2, true, $this->root);
        $red = $this->catalog->saveAttributeValue($color, 'Red', 1, true, $this->root);
        $this->assertThrows(ValidationException::class, fn () => $this->catalog->saveAttributeValue($size, 'm', 3, true, $this->root), 'already has');
        $this->assertThrows(DomainRuleException::class, fn () => $this->catalog->saveAttributeValue($weight, '500', 1, true, $this->root), 'typed on each variant');
        $p = $this->product('Cotton T-shirt', ['sku' => 'TSHIRT']);
        $vs = new VariantService(App::db());
        $v1 = $vs->create($p, ['attributes' => [$size => $m, $color => $red]], $this->root);
        $v2 = $vs->create($p, ['attributes' => [$size => $l, $color => $red], 'sku' => 'TSHIRT-L-RED'], $this->root);
        $v3 = $vs->create($p, ['name' => 'Gift pack', 'attributes' => [$weight => '250']], $this->root);
        $rows = $vs->forProduct((int) $p['id']);
        $this->assertSame(3, count($rows));
        $this->assertSame('M / Red', $rows[0]['name'], 'name from attributes');
        $this->assertSame('TSHIRT-01', $rows[0]['sku'], 'variant SKU from the variant pattern');
        $this->assertSame('250 g', $rows[2]['attributes'][0]['value']);
        $this->assertTrue(Uuid::isValid($rows[1]['uuid']) && $rows[1]['variant_code'] === $p['product_code'] . '-V02');
        $this->assertThrows(ValidationException::class, fn () => $vs->create($p, ['name' => 'Another M red', 'attributes' => [$color => $red, $size => $m]], $this->root), 'exactly these');
        $this->assertThrows(ValidationException::class, fn () => $vs->create($p, ['name' => 'x', 'sku' => 'TSHIRT'], $this->root), 'already used');
        $this->assertThrows(ValidationException::class, fn () => $vs->create($p, ['name' => 'Bad', 'attributes' => [$weight => 'heavy']], $this->root), 'number');
        $this->assertSame(1, (int) $this->products->find((int) $p['id'])['has_variants']);
        $vs->update($p, $vs->find((int) $p['id'], $v1), ['name' => 'Medium red', 'attributes' => [$size => $m, $color => $red]], $this->root);
        $vs->setStatus($p, $vs->find((int) $p['id'], $v3), 'discontinued', 'Pack withdrawn', $this->root);
        $this->assertSame('discontinued', $vs->find((int) $p['id'], $v3)['status']);
        // A variant SKU change goes through the SKU workflow.
        $this->enableWorkflow('product_sku_change');
        $req = $this->products->requestSku($p, $v2, '', 'Align with pattern', $this->root);
        $this->assertSame('pending', $req['status']);
        $this->assertSame('TSHIRT-L-RED', $vs->find((int) $p['id'], $v2)['sku'], 'unchanged until approved');
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');
        App::approvals()->approve((int) $req['approval_request_id'], $checker);
        $this->assertSame('TSHIRT-03', $vs->find((int) $p['id'], $v2)['sku']);
        // The attribute input type is locked once used.
        $this->assertThrows(ValidationException::class, fn () => $this->catalog->saveAttribute(['code' => 'size', 'name' => 'Size', 'input_type' => 'text', 'is_active' => 1], $this->root, $size), 'cannot change');
    }

    public function testBundleComponentsPreventLoops(): void
    {
        $vs = new VariantService(App::db());
        $a = $this->product('Shampoo');
        $b = $this->product('Conditioner');
        $kit = $this->product('Hair care kit', ['product_type_id' => $this->type('kit')]);
        $vs->addComponent($kit, (int) $a['id'], null, '1', null, $this->root);
        $vs->addComponent($kit, (int) $b['id'], null, '2.5', 'Two and a half', $this->root);
        $this->assertSame(2, count($vs->components((int) $kit['id'])));
        $big = $this->product('Gift hamper', ['product_type_id' => $this->type('bundle')]);
        $vs->addComponent($big, (int) $kit['id'], null, '1', null, $this->root);
        $this->assertThrows(ValidationException::class, fn () => $vs->addComponent($this->products->find((int) $kit['id']), (int) $big['id'], null, '1', null, $this->root), 'already contains');
        $this->assertThrows(ValidationException::class, fn () => $vs->addComponent($kit, (int) $kit['id'], null, '1', null, $this->root), 'itself');
        $this->assertThrows(DomainRuleException::class, fn () => $vs->addComponent($a, (int) $b['id'], null, '1', null, $this->root), 'bundle and kit');
        $this->assertThrows(ValidationException::class, fn () => $vs->addComponent($big, (int) $a['id'], null, '0', null, $this->root), 'greater than zero');
    }

    public function testPricesAreVersionedWithHistoryBoundsAndApproval(): void
    {
        $pr = new PricingService(App::db());
        $p = $this->product('Desk lamp');
        $today = BusinessDate::today();
        $past = BusinessDate::addDays($today, -30);
        $pr->add($p, ['price_type' => 'standard_selling', 'amount' => '1499', 'effective_from' => $past], $this->root);
        $pr->add($p, ['price_type' => 'minimum_selling', 'amount' => '1200', 'effective_from' => $past], $this->root);
        $pr->add($p, ['price_type' => 'maximum_selling', 'amount' => '1999', 'effective_from' => $past], $this->root);
        $pr->add($p, ['price_type' => 'standard_purchase', 'amount' => '850.50', 'effective_from' => $past], $this->root);
        $pr->add($p, ['price_type' => 'wholesale', 'amount' => '1300', 'effective_from' => $past], $this->root);
        $pr->add($p, ['price_type' => 'marketplace_selling', 'amount' => '1599', 'sales_channel' => 'amazon', 'effective_from' => $past], $this->root);
        $pr->add($p, ['price_type' => 'marketplace_selling', 'amount' => '1549', 'sales_channel' => 'Flipkart', 'effective_from' => $past], $this->root);
        $this->assertSame(7, count($pr->current((int) $p['id'])), 'different prices per type and channel');
        $this->assertThrows(ValidationException::class, fn () => $pr->add($p, ['price_type' => 'retail', 'amount' => '999'], $this->root), 'below the minimum');
        $this->assertThrows(ValidationException::class, fn () => $pr->add($p, ['price_type' => 'retail', 'amount' => '2500'], $this->root), 'above the maximum');
        $this->assertThrows(ValidationException::class, fn () => $pr->add($p, ['price_type' => 'marketplace_selling', 'amount' => '1500'], $this->root), 'sales channel');
        $this->assertThrows(ValidationException::class, fn () => $pr->add($p, ['price_type' => 'standard_selling', 'amount' => '-5'], $this->root), 'greater than zero');
        $this->assertThrows(ValidationException::class, fn () => $pr->add($p, ['price_type' => 'standard_selling', 'amount' => '1450', 'effective_from' => BusinessDate::addDays($past, -1)], $this->root), 'must start after');
        // A new version closes the old one; the old price is still readable for its dates.
        $pr->add($p, ['price_type' => 'standard_selling', 'amount' => '1599', 'effective_from' => $today], $this->root);
        $this->assertSame('1599.00', $pr->priceOn((int) $p['id'], null, 'standard_selling', $today)['amount']);
        $this->assertSame('1499.00', $pr->priceOn((int) $p['id'], null, 'standard_selling', BusinessDate::addDays($today, -1))['amount'], 'history preserved');
        $old = App::db()->first("SELECT * FROM product_prices WHERE product_id = ? AND price_type = 'standard_selling' AND amount = 1499", [$p['id']]);
        $this->assertSame('superseded', $old['status']);
        $this->assertSame(BusinessDate::addDays($today, -1), $old['effective_until']);
        // Future price can be cancelled and the previous one re-opens.
        $future = $pr->add($p, ['price_type' => 'standard_selling', 'amount' => '1699', 'effective_from' => BusinessDate::addDays($today, 10)], $this->root);
        $this->assertSame(1, count($pr->upcoming((int) $p['id'])));
        $pr->cancel($pr->find((int) $future['id']), 'Promotion cancelled', $this->root);
        $this->assertNull(App::db()->scalar("SELECT effective_until FROM product_prices WHERE product_id = ? AND price_type = 'standard_selling' AND amount = 1599", [$p['id']]));
        $this->assertThrows(DomainRuleException::class, fn () => $pr->cancel($pr->find((int) $old['id']), 'x', $this->root), 'add a new price');
        // With the workflow on, prices wait for approval and do not apply before.
        $this->enableWorkflow('product_price_change');
        $pending = $pr->add($p, ['price_type' => 'standard_purchase', 'amount' => '900', 'effective_from' => $today], $this->root);
        $this->assertSame('pending_approval', $pending['status']);
        $this->assertSame('850.50', $pr->priceOn((int) $p['id'], null, 'standard_purchase', $today)['amount']);
        $this->assertThrows(ValidationException::class, fn () => $pr->add($p, ['price_type' => 'standard_purchase', 'amount' => '910', 'effective_from' => $today], $this->root), 'waiting for approval');
        $req = (int) App::db()->scalar("SELECT id FROM approval_requests WHERE subject_type = 'product_price' AND subject_id = ?", [$pending['id']]);
        $this->assertThrows(AuthorizationException::class, fn () => App::approvals()->approve($req, $this->root), null);
        App::approvals()->approve($req, $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker'));
        $this->assertSame('900.00', $pr->priceOn((int) $p['id'], null, 'standard_purchase', $today)['amount']);
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM product_history WHERE product_id = ? AND event = 'price_set'", [$p['id']]));
    }

    public function testTaxConfigurationsValidateCodesSplitPermissionsAndKeepHistory(): void
    {
        $tax = new TaxConfigService(App::db());
        $p = $this->product('Bluetooth speaker');
        $svc = $this->product('Installation service', ['product_type_id' => $this->type('service')]);
        $goods = (int) App::db()->scalar("SELECT id FROM product_tax_categories WHERE code = 'goods'");
        $services = (int) App::db()->scalar("SELECT id FROM product_tax_categories WHERE code = 'services'");
        $r18 = (int) App::db()->scalar("SELECT id FROM gst_tax_rates WHERE code = 'GST18'");
        $r28 = (int) App::db()->scalar("SELECT id FROM gst_tax_rates WHERE code = 'GST28'");
        $this->assertSame('An HSN code has 4, 6 or 8 digits (e.g. 8517 or 85171300).', TaxConfigService::codeError('851', 'hsn'));
        $this->assertNull(TaxConfigService::codeError('85182200', 'hsn'));
        $this->assertNull(TaxConfigService::codeError('998729', 'sac'));
        $this->assertSame('A SAC code has 6 digits and starts with 99 (e.g. 998314).', TaxConfigService::codeError('123456', 'sac'));
        $this->assertThrows(ValidationException::class, fn () => $tax->add($p, ['tax_category_id' => $goods, 'gst_rate_id' => $r18], $this->root), 'HSN code');
        $this->assertThrows(ValidationException::class, fn () => $tax->add($svc, ['tax_category_id' => $services, 'gst_rate_id' => $r18, 'hsn_code' => '8518'], $this->root), 'SAC code');
        $this->assertThrows(ValidationException::class, fn () => $tax->add($p, ['tax_category_id' => $goods, 'hsn_code' => '8518'], $this->root), 'GST rate');
        $this->assertThrows(ValidationException::class, fn () => $tax->add($p, ['tax_category_id' => $goods, 'gst_rate_id' => $r28, 'hsn_code' => '8518'], $this->root), 'retired');
        $past = '2024-04-01';
        // Retired rates may record a past period.
        $tax->add($p, ['tax_category_id' => $goods, 'gst_rate_id' => $r28, 'hsn_code' => '85182200', 'effective_from' => $past, 'effective_until' => '2025-09-21'], $this->root);
        $tax->add($p, ['tax_category_id' => $goods, 'gst_rate_id' => $r18, 'hsn_code' => '85182200', 'effective_from' => '2025-09-22'], $this->root);
        $tax->add($svc, ['tax_category_id' => $services, 'gst_rate_id' => $r18, 'sac_code' => '998729'], $this->root);
        $this->assertSame('GST 18%', $tax->resolve((int) $p['id'], null, null)['rate_name']);
        $this->assertSame('GST 28% (retired)', $tax->resolve((int) $p['id'], null, null, '2025-01-15')['rate_name'], 'historical configuration preserved');
        // Registration-specific configuration needs the product to be mapped to that registration.
        $g1 = $this->gstReg(GstRegistrationTest::g('24AAKFM4821R1Z'), '24');
        $this->assertThrows(ValidationException::class, fn () => $tax->add($p, ['tax_category_id' => $goods, 'gst_rate_id' => $r18, 'hsn_code' => '85182200', 'gst_registration_id' => $g1], $this->root), 'not mapped');
        // Split permissions: HSN only vs GST rate only.
        $hsnOnly = $this->makeUser('hsn@example.com', [], name: 'HSN');
        $perms = new \App\Services\Admin\PermissionService(App::db());
        $pid = static fn (string $s): int => (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$s]);
        $perms->setUserOverrides((int) $hsnOnly['id'], [$pid('products.view') => 'allow', $pid('products.manage_hsn') => 'allow'], $this->root);
        App::gate()->flush();
        $this->assertThrows(AuthorizationException::class, fn () => $tax->add($p, ['tax_category_id' => $goods, 'gst_rate_id' => (int) App::db()->scalar("SELECT id FROM gst_tax_rates WHERE code = 'GST5'"), 'hsn_code' => '85182200',
            'effective_from' => BusinessDate::addDays(BusinessDate::today(), 1)], $hsnOnly), 'GST rates');
        $tax->add($p, ['tax_category_id' => $goods, 'gst_rate_id' => $r18, 'hsn_code' => '85183000', 'effective_from' => BusinessDate::addDays(BusinessDate::today(), 1)], $hsnOnly);
        $this->assertSame(3, count($tax->forProduct((int) $p['id'])));
        // Masters
        $tax->saveCode(['code' => '85182200', 'kind' => 'hsn', 'description' => 'Multiple loudspeakers, mounted in the same enclosure', 'default_rate_id' => $r18], $this->root);
        $this->assertThrows(ValidationException::class, fn () => $tax->saveCode(['code' => '99AB', 'kind' => 'sac', 'description' => 'x'], $this->root), 'SAC');
        $this->assertThrows(ValidationException::class, fn () => $tax->saveRate(['code' => 'GST18', 'name' => 'GST 18%', 'rate' => '19', 'is_active' => 1], $this->root, $r18), 'cannot change');
        $this->enableWorkflow('product_tax_change');
        $pending = $tax->add($svc, ['tax_category_id' => $services, 'gst_rate_id' => (int) App::db()->scalar("SELECT id FROM gst_tax_rates WHERE code = 'GST5'"), 'sac_code' => '998729',
            'effective_from' => BusinessDate::addDays(BusinessDate::today(), 5)], $this->root);
        $this->assertSame('pending_approval', $pending['status']);
        $tax->cancel($tax->find((int) $pending['id']), 'Wrong rate', $this->root);
        $this->assertSame('cancelled', $tax->find((int) $pending['id'])['status']);
    }

    public function testSuppliersValidateGstinPanDuplicatesAndKeepBankDetailsSeparate(): void
    {
        $g = GstRegistrationTest::g('24AABCS1429B1Z');
        $s1 = $this->supplier('Shree Packaging', $g);
        $this->assertSame('active', $s1['status']);
        $this->assertSame('AABCS1429B', $s1['pan'], 'PAN taken from the GSTIN');
        $this->assertSame('24', $s1['state_code']);
        $this->assertTrue(Uuid::isValid($s1['uuid']));
        $this->assertThrows(ValidationException::class, fn () => $this->supplier('Copy', $g), 'already has this GSTIN');
        $this->assertThrows(ValidationException::class, fn () => $this->supplier('Typo', substr($g, 0, 14) . ($g[14] === 'A' ? 'B' : 'A')), 'check character');
        $this->assertThrows(ValidationException::class, fn () => $this->supplier('Bad state', GstRegistrationTest::g('00AABCS1429B1Z')), 'state code');
        $this->assertThrows(ValidationException::class, fn () => $this->supplier('Wrong PAN', GstRegistrationTest::g('27AABCS1429B1Z'), ['pan' => 'AABCT1429B']), 'does not match');
        // Same PAN in another state: needs confirmation.
        $g27 = GstRegistrationTest::g('27AABCS1429B1Z');
        $this->assertThrows(ValidationException::class, fn () => $this->supplier('Shree Packaging Mumbai', $g27), 'same PAN');
        $s2 = $this->supplier('Shree Packaging Mumbai', $g27, ['duplicate_ok' => 1]);
        $this->assertThrows(ValidationException::class, fn () => $this->supplier('Unreg with GSTIN', $g27, ['gst_treatment' => 'unregistered', 'duplicate_ok' => 1]), 'Leave the GSTIN empty');
        $this->assertThrows(ValidationException::class, fn () => $this->supplier('Bad mobile', '', ['gst_treatment' => 'unregistered', 'mobile' => '12345']), 'mobile');
        [$ov] = $this->suppliers->create(['name' => 'Shenzhen Parts', 'supplier_type_id' => (int) App::db()->scalar("SELECT id FROM supplier_types WHERE code = 'importer'"),
            'gst_treatment' => 'overseas', 'country' => 'CN', 'default_currency' => 'USD', 'mobile' => '+8613800138000'], $this->root, true);
        $this->assertSame('CN', $this->suppliers->find($ov)['country']);
        // GSTIN changes on an active supplier go through the GSTIN workflow and need manage_gst_details.
        $this->enableWorkflow('supplier_gstin_change');
        $new = GstRegistrationTest::g('24AABCS1429B2Z');
        $r = $this->suppliers->update($s1, ['gstin' => $new, 'name' => 'Shree Packaging Ltd'] + $s1, $this->root, 'New registration after conversion');
        $this->assertSame(['name'], $r['direct']);
        $this->assertSame($g, $this->suppliers->find((int) $s1['id'])['gstin'], 'unchanged until approved');
        App::approvals()->approve((int) $r['change']['approval_request_id'], $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker'));
        $this->assertSame($new, $this->suppliers->find((int) $s1['id'])['gstin']);
        // Bank details: encrypted, masked, approved before use, never in company bank accounts.
        $this->enableWorkflow('supplier_bank_change');
        $bank = new SupplierBankService(App::db());
        $req = $bank->add($this->suppliers->find((int) $s1['id']), ['account_holder_name' => 'Shree Packaging Ltd', 'bank_name' => 'Axis Bank', 'account_number' => '9180 1004 5566 778',
            'ifsc_code' => 'UTIB0000123', 'account_type' => 'current'], $this->root);
        $row = App::db()->first('SELECT * FROM supplier_bank_details WHERE supplier_id = ?', [$s1['id']]);
        $this->assertSame('pending_approval', $row['status']);
        $this->assertSame('6778', $row['account_number_last4']);
        $this->assertNotContains('918010045566778', (string) $row['account_number_encrypted'], 'stored encrypted');
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_accounts'), 'not a company bank account');
        $this->assertNotContains('918010045566778', json_encode(App::db()->select('SELECT old_values, new_values FROM activity_logs')) . json_encode(App::db()->select('SELECT new_values FROM supplier_history')), 'never logged in full');
        App::approvals()->approve((int) $req['approval_request_id'], App::db()->first("SELECT * FROM users WHERE email = 'checker@example.com'"));
        $row = App::db()->first('SELECT * FROM supplier_bank_details WHERE supplier_id = ?', [$s1['id']]);
        $this->assertSame('active', $row['status']);
        $this->assertSame(1, (int) $row['is_primary']);
        $this->assertSame('918010045566778', $bank->fullNumber($row, $this->root));
        $this->assertThrows(ValidationException::class, fn () => $bank->add($this->suppliers->find((int) $s1['id']), ['account_holder_name' => 'Shree Packaging', 'bank_name' => 'Axis Bank', 'account_number' => '918010045566778', 'ifsc_code' => 'UTIB0000123'], $this->root), 'already recorded');
        $this->assertThrows(ValidationException::class, fn () => $bank->add($this->suppliers->find((int) $s2['id']), ['account_holder_name' => 'Shree Packaging', 'bank_name' => 'Axis Bank', 'account_number' => '918010045566778', 'ifsc_code' => 'UTIB0000123'], $this->root), 'already has this account number');
        $this->assertThrows(ValidationException::class, fn () => $bank->add($this->suppliers->find($ov), ['account_holder_name' => 'Shenzhen Parts', 'bank_name' => 'Bank of China', 'account_number' => 'CN12345678'], $this->root), 'SWIFT');
    }

    public function testProductsAndSuppliersAreManyToManyWithSupplierPricesAndPreferred(): void
    {
        $maps = new SupplierMappingService(App::db());
        $pr = new PricingService(App::db());
        $a = $this->product('Water bottle');
        $b = $this->product('Lunch box');
        $s1 = $this->supplier('Alpha Plastics', GstRegistrationTest::g('24AAACA1234A1Z'));
        $s2 = $this->supplier('Beta Polymers', GstRegistrationTest::g('27AAACB1234B1Z'));
        [$m1, $price1] = $maps->add($a, ['supplier_id' => $s1['id'], 'supplier_product_code' => 'AP-WB-01', 'min_order_qty' => '100', 'lead_time_days' => '7', 'purchase_price' => '62.50', 'is_preferred' => 1], $this->root);
        [$m2] = $maps->add($a, ['supplier_id' => $s2['id'], 'supplier_sku' => 'BP-778', 'min_order_qty' => '50', 'max_order_qty' => '5000', 'priority' => '2', 'purchase_price' => '58.00'], $this->root);
        $maps->add($b, ['supplier_id' => $s1['id'], 'purchase_price' => '110'], $this->root);
        $this->assertSame(2, count($maps->forProduct((int) $a['id'])), 'one product, many suppliers');
        $this->assertSame(2, count($maps->forSupplier((int) $s1['id'])), 'one supplier, many products');
        $this->assertSame('active', $price1['status']);
        $rows = $maps->forProduct((int) $a['id']);
        $this->assertSame('Alpha Plastics', $rows[0]['supplier_name'], 'preferred first, even though Beta is cheaper');
        $this->assertSame(6250, $rows[0]['purchase_price']);
        $this->assertSame(5800, $rows[1]['purchase_price']);
        $this->assertThrows(DomainRuleException::class, fn () => $maps->add($a, ['supplier_id' => $s1['id']], $this->root), 'already linked');
        $this->assertThrows(ValidationException::class, fn () => $maps->add($b, ['supplier_id' => $s2['id'], 'min_order_qty' => '10', 'max_order_qty' => '5'], $this->root), 'below the minimum');
        $this->assertThrows(ValidationException::class, fn () => $pr->add($b, ['price_type' => 'supplier_purchase', 'amount' => '99', 'supplier_id' => $s2['id']], $this->root), 'Link this supplier');
        // Supplier price history
        $pr->add($a, ['price_type' => 'supplier_purchase', 'amount' => '64', 'supplier_id' => $s1['id'], 'effective_from' => BusinessDate::addDays(BusinessDate::today(), 1)], $this->root);
        $this->assertSame(2, count($pr->history((int) $a['id'], ['supplier' => $s1['id']])));
        // Preferred supplier change through approval
        $this->enableWorkflow('product_preferred_supplier');
        $req = $maps->requestPreferred($this->products->find((int) $a['id']), $m2, 'Better lead time', $this->root);
        $this->assertSame(1, (int) $maps->find($m1)['is_preferred'], 'unchanged until approved');
        App::approvals()->approve((int) $req['approval_request_id'], $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker'));
        $this->assertSame(0, (int) $maps->find($m1)['is_preferred']);
        $this->assertSame(1, (int) $maps->find($m2)['is_preferred'], 'one preferred supplier');
        $this->assertThrows(DomainRuleException::class, fn () => $maps->update($a, $maps->find($m2), ['status' => 'inactive', 'priority' => 2], $this->root), 'preferred supplier');
        $maps->end($a, $maps->find($m1), null, 'Supplier stopped this line', $this->root);
        $this->assertSame('ended', $maps->find($m1)['status']);
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM supplier_history WHERE supplier_id = ? AND event = 'product_supplier_unlinked'", [$s1['id']]));
    }

    public function testGstAndBusinessActivityMappingsForProductsAndSuppliers(): void
    {
        $g1 = $this->gstReg(GstRegistrationTest::g('24AAKFM4821R1Z'), '24');
        $g2 = $this->gstReg(GstRegistrationTest::g('27AAKFM4821R1Z'), '27');
        $a = $this->product('Product A');
        $b = $this->product('Product B');
        $pg = new DatedMappingService(App::db(), 'product_gst');
        $pg->add($a, $g1, [], $this->root);
        $pg->add($a, $g2, [], $this->root);
        $pg->add($b, $g1, [], $this->root);
        $this->assertSame(2, count($pg->list((int) $a['id'])), 'one product, many registrations');
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM product_gst_mappings WHERE gst_registration_id = ? AND status = 'active'", [$g1]), 'one registration, many products');
        $this->assertThrows(DomainRuleException::class, fn () => $pg->add($a, $g1, [], $this->root), 'already linked');
        $mid = (int) App::db()->scalar('SELECT id FROM product_gst_mappings WHERE product_id = ? AND gst_registration_id = ?', [$a['id'], $g2]);
        $pg->end($a, $mid, null, 'Stopped selling in Maharashtra', $this->root);
        $this->assertSame([$g1], $pg->activeTargetIds((int) $a['id']));
        $this->assertTrue((new \App\Services\Gst\GstLinkRegistry(App::db()))->isReferenced($g1), 'a referenced registration cannot be archived');
        $act = (int) App::db()->scalar("SELECT id FROM gst_business_activities WHERE code = 'supplier_payments'");
        (new DatedMappingService(App::db(), 'product_activity'))->add($a, $act, [], $this->root);
        $s = $this->supplier('Gamma Traders', GstRegistrationTest::g('24AAACG1234G1Z'));
        (new DatedMappingService(App::db(), 'supplier_gst'))->add($s, $g1, [], $this->root);
        (new DatedMappingService(App::db(), 'supplier_activity'))->add($s, $act, ['notes' => 'Buys packaging'], $this->root);
        $this->assertSame(1, count((new SupplierService(App::db()))->paginate(['gst' => $g1], 1, 10, ['1 = 1', []])->items));
        $this->assertSame(1, count($this->products->paginate(['activity' => $act], 1, 10, ['1 = 1', []])->items));
    }

    public function testApprovalsForNewProductsSuppliersArchiveAndSelfApprovalBlock(): void
    {
        $this->enableWorkflow('product_create');
        $this->enableWorkflow('supplier_create');
        $this->enableWorkflow('product_archive');
        $this->enableWorkflow('product_update');
        [$id, $change] = $this->products->create(['name' => 'Smart watch', 'product_type_id' => $this->type('physical'), 'unit' => 'pcs'], $this->root, true);
        $this->assertSame('pending_approval', $this->products->find($id)['status']);
        $this->assertThrows(DomainRuleException::class, fn () => ProductService::assertUsable($this->products->find($id)), 'cannot be used');
        $this->assertThrows(AuthorizationException::class, fn () => App::approvals()->approve((int) $change['approval_request_id'], $this->root), null);
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');
        App::approvals()->reject((int) $change['approval_request_id'], $checker, 'Add a description first');
        $p = $this->products->find($id);
        $this->assertSame('draft', $p['status']);
        $this->assertSame('Add a description first', $p['rejection_reason']);
        $change = $this->products->submit($p, $this->root);
        App::approvals()->approve((int) $change['approval_request_id'], $checker);
        $p = $this->products->find($id);
        $this->assertSame('active', $p['status']);
        ProductService::assertUsable($p);
        // Key detail change waits; descriptions apply at once.
        $r = $this->products->update($p, ['name' => 'Smart watch Pro', 'short_description' => 'AMOLED display'] + $p, $this->root, 'New model name');
        $this->assertSame(['short_description'], $r['direct']);
        $this->assertSame('Smart watch', $this->products->find($id)['name']);
        App::approvals()->approve((int) $r['change']['approval_request_id'], $checker);
        $this->assertSame('Smart watch Pro', $this->products->find($id)['name']);
        // Archive through approval; archived products are read-only and not usable unless the exception is set.
        $arch = $this->products->requestArchive($this->products->find($id), 'End of life', $this->root);
        App::approvals()->approve((int) $arch['approval_request_id'], $checker);
        $p = $this->products->find($id);
        $this->assertSame('archived', $p['status']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->products->update($p, $p, $this->root), 'read-only');
        $this->assertThrows(DomainRuleException::class, fn () => ProductService::assertUsable($p), 'cannot be used');
        $this->products->setArchivedUse($p, true, 'Clear remaining stock', $this->root);
        ProductService::assertUsable($this->products->find($id));
        $this->assertSame('Smart watch Pro', $this->products->find($id)['name'], 'archived products keep their details for history');
        $this->products->restore($this->products->find($id), $this->root);
        $this->assertSame('active', $this->products->find($id)['status']);
        $events = array_column($this->products->historyFor($id), 'event');
        foreach (['created', 'status', 'updated', 'archived', 'archived_use', 'restored'] as $e) {
            $this->assertTrue(in_array($e, $events, true), "history has {$e}");
        }
        // Supplier creation approval
        [$sid, $sc] = $this->suppliers->create(['name' => 'Delta Corp', 'supplier_type_id' => (int) App::db()->scalar("SELECT id FROM supplier_types WHERE code = 'wholesaler'"),
            'gst_treatment' => 'unregistered', 'country' => 'IN'], $this->root, true);
        $this->assertSame('pending_approval', $this->suppliers->find($sid)['status']);
        $this->assertThrows(DomainRuleException::class, fn () => SupplierService::assertUsable($this->suppliers->find($sid)), 'cannot be used');
        App::approvals()->approve((int) $sc['approval_request_id'], $checker);
        $this->assertSame('active', $this->suppliers->find($sid)['status']);
    }

    public function testDocumentsImagesAndStatusLifecycle(): void
    {
        $p = $this->product('Ceramic mug');
        $media = new ProductMediaService(App::db());
        $png = sys_get_temp_dir() . '/p6-' . bin2hex(random_bytes(4)) . '.png';
        imagepng(imagecreatetruecolor(40, 30), $png);
        $file = static fn (string $path, string $name): array => ['name' => $name, 'tmp_name' => $path, 'error' => 0, 'size' => filesize($path)];
        $i1 = $media->uploadImage($p, $file($png, 'mug-front.png'), [], $this->root, false);
        imagepng(imagecreatetruecolor(20, 20), $png);
        $i2 = $media->uploadImage($p, $file($png, 'mug-side.png'), ['caption' => 'Side'], $this->root, false);
        $imgs = $media->images((int) $p['id']);
        $this->assertSame(1, (int) $imgs[0]['is_primary']);
        $this->assertSame(40, (int) $imgs[0]['width']);
        $media->setPrimary($p, App::db()->first('SELECT * FROM product_images WHERE id = ?', [$i2]), $this->root);
        $this->assertSame($i2, (int) $media->images((int) $p['id'])[0]['id']);
        $media->archiveImage($p, App::db()->first('SELECT * FROM product_images WHERE id = ?', [$i2]), 'Blurry', $this->root);
        $this->assertSame(1, (int) App::db()->scalar('SELECT is_primary FROM product_images WHERE id = ?', [$i1]), 'next image becomes primary');
        $fake = sys_get_temp_dir() . '/p6-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($fake, '<?php echo 1;');
        $this->assertThrows(ValidationException::class, fn () => $media->uploadImage($p, $file($fake, 'evil.png'), [], $this->root, false), null);
        $pdf = sys_get_temp_dir() . '/p6-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $d = $media->uploadDocument($p, $file($pdf, 'warranty.pdf'), ['category' => 'warranty'], $this->root, false);
        $doc = App::db()->first('SELECT * FROM product_documents WHERE id = ?', [$d]);
        $this->assertTrue(!str_contains((string) $doc['stored_name'], 'warranty'), 'random stored name');
        $this->assertTrue(is_file(ProductMediaService::store()->path((string) $doc['stored_name'])), 'stored outside public/');
        $media->documentResponse($doc, $p, false);
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'product.document_downloaded'"));
        $this->assertThrows(ValidationException::class, fn () => $media->uploadDocument($p, $file($pdf, 'a.pdf'), ['category' => 'invoice'], $this->root, false), 'category');
        // Supplier documents: bank proofs need view_bank_details.
        $s = $this->supplier('Epsilon Ceramics', GstRegistrationTest::g('24AAACE1234E1Z'));
        $det = new SupplierDetailService(App::db());
        $det->uploadDocument($s, $file($pdf, 'gst.pdf'), ['category' => 'gst_certificate'], $this->root, false);
        $clerk = $this->makeUser('clerk@example.com', [], name: 'Clerk');
        $pid = static fn (string $x): int => (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$x]);
        (new \App\Services\Admin\PermissionService(App::db()))->setUserOverrides((int) $clerk['id'], [$pid('suppliers.view') => 'allow', $pid('suppliers.manage_documents') => 'allow'], $this->root);
        App::gate()->flush();
        $this->assertThrows(AuthorizationException::class, fn () => $det->uploadDocument($s, $file($pdf, 'cheque.pdf'), ['category' => 'bank_proof'], $clerk, false), null);
        $det->saveAddress($s, ['address_type' => 'business', 'line1' => '12 Industrial Estate', 'city' => 'Morbi', 'state_id' => (int) App::db()->scalar("SELECT id FROM gst_states WHERE code = '24'"), 'pin_code' => '363641'], $this->root);
        $this->assertThrows(ValidationException::class, fn () => $det->saveAddress($s, ['address_type' => 'billing', 'line1' => '12 Road', 'city' => 'Morbi', 'state_id' => 1, 'pin_code' => '0123'], $this->root), 'PIN');
        $this->assertSame(1, (int) $det->addresses((int) $s['id'])[0]['is_default']);
        // Product status transitions
        $this->products->changeStatus($p, 'discontinued', 'Replaced by new mug', $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $this->products->changeStatus($this->products->find((int) $p['id']), 'inactive', 'x', $this->root), 'cannot be changed');
        $this->products->changeStatus($this->products->find((int) $p['id']), 'active', null, $this->root);
        foreach ([$png, $fake, $pdf] as $f) {
            @unlink($f);
        }
    }

    public function testSupplierPaymentReferencesUseExistingBankTransactionsOnly(): void
    {
        $acc = new \App\Services\Bank\BankAccountService(App::db());
        [$aid] = $acc->create(['display_name' => 'Operations', 'bank_name' => 'HDFC Bank', 'account_holder_name' => 'Mehta Traders', 'account_number' => '50100123456789', 'ifsc_code' => 'HDFC0001234',
            'account_type_id' => (int) App::db()->scalar("SELECT id FROM bank_account_types WHERE code = 'current'"), 'opening_balance' => '100000', 'opening_balance_date' => '2025-04-01'], $this->root, true);
        $txns = new \App\Services\Bank\BankTransactionService(App::db());
        $w = $txns->create(['bank_account_id' => $aid, 'type' => 'withdrawal', 'amount' => '12500', 'transaction_date' => '2026-01-10', 'counterparty_name' => 'Zeta Supplies'], $this->root, true);
        $d = $txns->create(['bank_account_id' => $aid, 'type' => 'deposit', 'amount' => '500', 'transaction_date' => '2026-01-11'], $this->root, true);
        $before = (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions');
        $s = $this->supplier('Zeta Supplies', GstRegistrationTest::g('24AAACZ1234Z1Z'));
        $det = new SupplierDetailService(App::db());
        $all = ['1 = 1', []];
        $this->assertSame(1, count($det->linkableTransactions($all)));
        $det->linkPayment($s, (int) $w['id'], 'INV-2026-001', null, $all, $this->root);
        $this->assertThrows(ValidationException::class, fn () => $det->linkPayment($s, (int) $w['id'], null, null, $all, $this->root), 'already referenced');
        $this->assertThrows(ValidationException::class, fn () => $det->linkPayment($s, (int) $d['id'], null, null, $all, $this->root), 'withdrawals');
        $this->assertSame($before, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions'), 'no bank transaction created');
        $this->assertSame(1, count($det->paymentLinks((int) $s['id'], $all)));
        $this->assertSame([], $det->paymentLinks((int) $s['id'], ['1 = 0', []]), 'hidden outside the bank scope');
    }

    public function testReportsFiltersAndIntegrationFoundations(): void
    {
        $cat = $this->catalog->saveCategory(['code' => 'TOYS', 'name' => 'Toys'], $this->root);
        $a = $this->product('Building blocks', ['category_id' => $cat]);
        $b = $this->product('Puzzle', ['category_id' => $cat]);
        $this->product('Board game');
        $s = $this->supplier('Kappa Toys', GstRegistrationTest::g('24AAACK1234K1Z'));
        (new SupplierMappingService(App::db()))->add($a, ['supplier_id' => $s['id'], 'purchase_price' => '300'], $this->root);
        (new PricingService(App::db()))->add($b, ['price_type' => 'standard_selling', 'amount' => '499'], $this->root);
        (new TaxConfigService(App::db()))->add($a, ['tax_category_id' => (int) App::db()->scalar("SELECT id FROM product_tax_categories WHERE code = 'goods'"),
            'gst_rate_id' => (int) App::db()->scalar("SELECT id FROM gst_tax_rates WHERE code = 'GST5'"), 'hsn_code' => '9503'], $this->root);
        $this->products->changeStatus($this->products->find((int) $b['id']), 'inactive', 'Seasonal', $this->root);
        $reports = new ProductReportService(App::db());
        foreach (array_keys(ProductReportService::REPORTS) as $key) {
            $r = $reports->run($this->root, $key, []);
            $this->assertTrue(isset($r['columns'], $r['rows'], $r['title']), $key);
        }
        $this->assertSame(3, count($reports->run($this->root, 'products', [])['rows']));
        $this->assertSame(2, count($reports->run($this->root, 'products', ['category' => $cat])['rows']), 'category filter');
        $this->assertSame(2, count($reports->run($this->root, 'no_suppliers', [])['rows']));
        $this->assertSame(2, count($reports->run($this->root, 'no_tax', [])['rows']));
        $this->assertSame(1, count($reports->run($this->root, 'products', ['hsn' => '95'])['rows']), 'HSN filter');
        $this->assertSame(1, count($reports->run($this->root, 'inactive', [])['rows']));
        $this->assertSame(1, count($reports->run($this->root, 'supplier_mappings', [])['rows']));
        $sup = new SupplierReportService(App::db());
        foreach (array_keys(SupplierReportService::REPORTS) as $key) {
            $r = $sup->run($this->root, $key, []);
            $this->assertTrue(isset($r['columns'], $r['rows']), $key);
        }
        $this->assertSame(30000, $sup->run($this->root, 'purchase_prices', [])['rows'][0]['price']);
        $this->assertSame(0, count($sup->run($this->root, 'no_products', [])['rows']));
        // Dashboard counts come from the data
        $d = (new \App\Services\Product\ProductDashboardService(App::db()))->data([], ['1 = 1', []]);
        $this->assertSame(3, $d['total']);
        $this->assertSame(1, $d['status']['inactive'] ?? 0);
        $this->assertSame(2, $d['no_supplier']);
        // Integration foundations for Phases 7–12: stable IDs, as-of lookups and usability checks.
        $found = $this->products->findByUuid($a['uuid']);
        $this->assertSame((int) $a['id'], (int) $found['id']);
        $this->assertSame('GST 5%', (new TaxConfigService(App::db()))->resolve((int) $a['id'], null, null)['rate_name']);
        $this->assertSame('300.00', (new PricingService(App::db()))->priceOn((int) $a['id'], null, 'supplier_purchase', BusinessDate::today(), (int) $s['id'])['amount']);
        SupplierService::assertUsable($s);
    }
}
