<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Product\PricingService;
use App\Services\Product\ProductService;
use App\Services\Product\SupplierMappingService;
use App\Services\Supplier\SupplierBankService;
use App\Services\Supplier\SupplierDetailService;
use App\Services\Supplier\SupplierService;
use Tests\Integration\GstRegistrationTest;

/** Phase 6 security through real HTTP requests: record and field scope, role and user permissions, bank details, documents, exports, CSRF. */
final class ProductSecurityTest extends HttpTestCase
{
    private array $root;
    private int $lamp;
    private int $mug;
    private int $alpha;
    private int $bravo;

    public function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $products = new ProductService($db);
        $type = (int) $db->scalar("SELECT id FROM product_types WHERE code = 'physical'");
        $this->lamp = $products->create(['name' => 'Brass Table Lamp', 'product_type_id' => $type, 'unit' => 'pcs', 'internal_notes' => 'Margin target 45%'], $this->root, true)[0];
        $this->mug = $products->create(['name' => 'Stoneware Mug', 'product_type_id' => $type, 'unit' => 'pcs'], $this->root, true)[0];
        $pricing = new PricingService($db);
        $pricing->add($products->find($this->lamp), ['price_type' => 'standard_purchase', 'amount' => '1234.56', 'effective_from' => '2026-01-01'], $this->root);
        $pricing->add($products->find($this->lamp), ['price_type' => 'standard_selling', 'amount' => '2499', 'effective_from' => '2026-01-01'], $this->root);
        $suppliers = new SupplierService($db);
        $mfr = (int) $db->scalar("SELECT id FROM supplier_types WHERE code = 'manufacturer'");
        $this->alpha = $suppliers->create(['name' => 'Alpha Brassware', 'supplier_type_id' => $mfr, 'gst_treatment' => 'registered', 'gstin' => GstRegistrationTest::g('09AAACA1234A1Z'),
            'country' => 'IN', 'contact_person' => 'Secret Contact', 'mobile' => '9876500001'], $this->root, true)[0];
        $this->bravo = $suppliers->create(['name' => 'Bravo Ceramics', 'supplier_type_id' => $mfr, 'gst_treatment' => 'registered', 'gstin' => GstRegistrationTest::g('09AAACB5678B1Z'),
            'country' => 'IN'], $this->root, true)[0];
        (new SupplierBankService($db))->add($suppliers->find($this->alpha), ['account_holder_name' => 'Alpha Brassware', 'bank_name' => 'SBI', 'account_number' => '30214567890',
            'ifsc_code' => 'SBIN0011234', 'account_type' => 'current', 'is_primary' => 1], $this->root);
        (new SupplierMappingService($db))->add($products->find($this->lamp), ['supplier_id' => $this->alpha, 'purchase_price' => '1200', 'is_preferred' => 1, 'effective_from' => '2026-01-01'], $this->root);
        $pdf = sys_get_temp_dir() . '/p6-test.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
        $detail = new SupplierDetailService($db);
        $detail->uploadDocument($suppliers->find($this->alpha), ['name' => 'gst.pdf', 'tmp_name' => $pdf, 'error' => 0, 'size' => filesize($pdf)], ['category' => 'gst_certificate', 'title' => 'GST certificate'], $this->root, false);
        $detail->uploadDocument($suppliers->find($this->alpha), ['name' => 'cheque.pdf', 'tmp_name' => $pdf, 'error' => 0, 'size' => filesize($pdf)], ['category' => 'bank_proof', 'title' => 'Cancelled cheque'], $this->root, false);
        $acct = $this->makeUser('acct@example.com', ['accountant'], name: 'Accountant');
        (new ResourceAssignmentService($db))->sync((int) $acct['id'], 'supplier', [$this->bravo], $this->root);
        $this->makeUser('viewer@example.com', ['viewer'], name: 'Viewer');
        $this->makeUser('manager@example.com', ['manager'], name: 'Manager');
    }

    private function fieldId(string $module, string $field): int
    {
        return (int) App::db()->scalar('SELECT f.id FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE m.module_key = ? AND f.field_key = ?', [$module, $field]);
    }

    private function permission(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
    }

    public function testViewerHasNoAccessAndAccountantSeesOnlyAssignedSuppliers(): void
    {
        $v = $this->browser();
        $this->signIn($v, 'viewer@example.com');
        foreach (['/products', '/products/dashboard', '/products/' . $this->lamp, '/products/reports', '/suppliers', '/suppliers/' . $this->alpha, '/suppliers/reports/suppliers'] as $url) {
            $this->assertSame(403, $v->get($url)['status'], $url);
        }
        $b = $this->browser();
        $this->signIn($b, 'acct@example.com');
        $list = $b->get('/suppliers')['body'];
        $this->assertContains('Bravo Ceramics', $list);
        $this->assertNotContains('Alpha Brassware', $list);
        $this->assertSame(404, $b->get('/suppliers/' . $this->alpha)['status'], 'unassigned supplier hidden as 404');
        $this->assertSame(200, $b->get('/suppliers/' . $this->bravo)['status']);
        $csv = $b->get('/suppliers/reports/suppliers/export?format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertContains('Bravo Ceramics', $csv['body']);
        $this->assertNotContains('Alpha Brassware', $csv['body'], 'exports are record-scoped');
        foreach (['/suppliers/dashboard', '/suppliers/documents', '/suppliers/product-mappings', '/products/' . $this->lamp . '?tab=suppliers', '/products/' . $this->lamp . '?tab=overview',
            '/products/supplier-mappings', '/products/reports/supplier_mappings', '/products?supplier=' . $this->alpha] as $url) {
            $this->assertNotContains('Alpha Brassware', $b->get($url)['body'], $url);
        }
        // Read-only: no create, edit, pricing, bank details.
        $this->assertSame(403, $b->get('/products/create')['status']);
        $this->assertSame(403, $b->get('/suppliers/' . $this->bravo . '/edit')['status']);
        $this->assertSame(403, $b->get('/suppliers/bank-details')['status']);
        $b->get('/products/' . $this->lamp);
        $this->assertSame(403, $b->post('/products/' . $this->lamp . '/prices', ['price_type' => 'standard_selling', 'amount' => '1', 'effective_from' => '2026-02-01'])['status']);
    }

    public function testManagerCanMaintainCatalogueButNotBankDetailsOrSettings(): void
    {
        $m = $this->browser();
        $this->signIn($m, 'manager@example.com');
        $this->assertSame(200, $m->get('/products/create')['status']);
        $r = $m->post('/products', ['name' => 'Linen Cushion', 'product_type_id' => (int) App::db()->scalar("SELECT id FROM product_types WHERE code = 'physical'"), 'unit' => 'pcs', 'action' => 'submit']);
        $this->assertTrue(in_array($r['status'], [302, 303], true), 'manager creates a product');
        $this->assertSame('active', App::db()->scalar("SELECT status FROM products WHERE name = 'Linen Cushion'"));
        $this->assertSame(403, $m->get('/products/settings')['status']);
        $this->assertSame(403, $m->get('/suppliers/bank-details')['status']);
        $page = $m->get('/suppliers/' . $this->alpha)['body'];
        $this->assertNotContains('tab=bank', $page, 'bank tab not offered without permission');
        $forced = $m->get('/suppliers/' . $this->alpha . '?tab=bank')['body'];
        $this->assertNotContains('Add bank details', $forced, 'bank tab falls back to the overview');
        $this->assertNotContains('7890', $forced);
        $m->get('/suppliers/' . $this->alpha);
        $this->assertSame(403, $m->post('/suppliers/' . $this->alpha . '/bank/1/reveal', [])['status']);
        $docs = $m->get('/suppliers/' . $this->alpha . '?tab=documents')['body'];
        $this->assertContains('GST certificate', $docs);
        $this->assertNotContains('Cancelled cheque', $docs, 'bank proofs hidden without bank-detail permission');
        $bankDoc = (int) App::db()->scalar("SELECT id FROM supplier_documents WHERE category = 'bank_proof'");
        $this->assertSame(403, $m->get('/suppliers/' . $this->alpha . '/documents/' . $bankDoc)['status'], 'bank proof not downloadable');
    }

    public function testUserSpecificOverridesAndFieldRules(): void
    {
        $id = (int) App::db()->scalar("SELECT id FROM users WHERE email = 'manager@example.com'");
        $perms = new PermissionService(App::db());
        $perms->setUserOverrides($id, [$this->permission('products.export') => 'deny', $this->permission('products.create') => 'deny',
            $this->permission('products.download_documents') => 'deny'], $this->root, null, 'Catalogue read-only this month');
        $perms->setUserFieldAccess($id, [$this->fieldId('products', 'purchase_cost') => 'masked', $this->fieldId('products', 'internal_notes') => 'hidden',
            $this->fieldId('suppliers', 'contact_person') => 'hidden', $this->fieldId('suppliers', 'gstin') => 'masked'], $this->root);
        $b = $this->browser();
        $this->signIn($b, 'manager@example.com');
        $this->assertSame(403, $b->get('/products/create')['status'], 'user-specific deny overrides the role grant');
        $this->assertSame(403, $b->get('/products/export?format=csv')['status']);
        $pricing = $b->get('/products/' . $this->lamp . '?tab=pricing')['body'];
        $this->assertNotContains('1,234.56', $pricing, 'purchase cost masked');
        $this->assertContains('2,499.00', $pricing, 'selling price still visible');
        $this->assertNotContains('Margin target', $b->get('/products/' . $this->lamp . '?tab=information')['body'], 'hidden field not rendered');
        $this->assertNotContains('1,200.00', $b->get('/suppliers/' . $this->alpha . '?tab=products')['body'], 'supplier purchase price masked');
        $sup = $b->get('/suppliers/' . $this->alpha . '?tab=contact')['body'] . $b->get('/suppliers')['body'];
        $this->assertNotContains('Secret Contact', $sup);
        $gstin = (string) App::db()->scalar('SELECT gstin FROM suppliers WHERE id = ?', [$this->alpha]);
        $this->assertNotContains($gstin, $b->get('/suppliers')['body'], 'GSTIN masked in the list');
        $csv = $b->get('/suppliers/reports/contacts/export?format=csv')['body'] . $b->get('/suppliers/reports/gst/export?format=csv')['body'];
        $this->assertNotContains('Secret Contact', $csv, 'hidden in exports');
        $this->assertNotContains($gstin, $csv, 'masked in exports');
        $this->assertNotContains('1234.56', $b->get('/products/reports/pricing/export?format=csv')['body'], 'masked amounts in exports');
    }

    public function testAdminFlowsCsrfBankRevealAndAllPages(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/products/' . $this->mug);
        $this->assertSame(419, $b->post('/products/' . $this->mug . '/status', ['status' => 'inactive', 'reason' => 'x', '_csrf' => 'wrong'], false)['status']);
        $this->assertSame('active', App::db()->scalar('SELECT status FROM products WHERE id = ?', [$this->mug]));
        $page = $b->get('/suppliers/' . $this->alpha . '?tab=bank')['body'];
        $this->assertNotContains('30214567890', $page, 'full number never rendered');
        $this->assertContains('7890', $page);
        $reveal = $b->post('/suppliers/' . $this->alpha . '/bank/1/reveal', []);
        $this->assertSame('30214567890', json_decode($reveal['body'], true)['number']);
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'supplier.account_number_viewed'"), 'full-number views are logged');
        $this->assertFalse((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE new_values LIKE '%30214567890%' OR old_values LIKE '%30214567890%' OR description LIKE '%30214567890%'"), 'never logged in full');
        $this->assertFalse((bool) App::db()->scalar("SELECT 1 FROM supplier_history WHERE new_values LIKE '%30214567890%'"));
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_accounts'), 'supplier accounts never become company bank accounts');
        // GSTIN check endpoint
        $b->get('/suppliers/create');
        $gstin = (string) App::db()->scalar('SELECT gstin FROM suppliers WHERE id = ?', [$this->alpha]);
        $check = json_decode($b->post('/suppliers/check-gstin', ['gstin' => $gstin])['body'], true);
        $this->assertTrue($check['valid'] && str_contains((string) $check['duplicate'], 'Alpha Brassware'));
        foreach (['/products/dashboard', '/products', '/products/create', '/products/history', '/products/categories', '/products/brands', '/products/attributes', '/products/variants',
            '/products/pricing', '/products/tax', '/products/supplier-mappings', '/products/documents', '/products/settings', '/products/reports', '/suppliers/dashboard', '/suppliers',
            '/suppliers/create', '/suppliers/types', '/suppliers/addresses', '/suppliers/bank-details', '/suppliers/documents', '/suppliers/product-mappings', '/suppliers/history', '/suppliers/reports'] as $url) {
            $this->assertSame(200, $b->get($url)['status'], $url);
        }
        foreach (array_keys(\App\Controllers\Product\ProductController::TABS) as $tab) {
            $this->assertSame(200, $b->get('/products/' . $this->lamp . '?tab=' . $tab)['status'], 'product ' . $tab);
        }
        foreach (array_keys(\App\Controllers\Supplier\SupplierController::TABS) as $tab) {
            $this->assertSame(200, $b->get('/suppliers/' . $this->alpha . '?tab=' . $tab)['status'], 'supplier ' . $tab);
        }
        foreach (array_keys(\App\Services\Product\ProductReportService::REPORTS) as $report) {
            $this->assertSame(200, $b->get('/products/reports/' . $report)['status'], $report);
            foreach (['csv', 'xlsx', 'pdf'] as $f) {
                $this->assertSame(200, $b->get("/products/reports/{$report}/export?format={$f}")['status'], "{$report}.{$f}");
            }
        }
        foreach (array_keys(\App\Services\Supplier\SupplierReportService::REPORTS) as $report) {
            $this->assertSame(200, $b->get('/suppliers/reports/' . $report)['status'], $report);
            foreach (['csv', 'xlsx', 'pdf'] as $f) {
                $this->assertSame(200, $b->get("/suppliers/reports/{$report}/export?format={$f}")['status'], "{$report}.{$f}");
            }
        }
        $doc = (int) App::db()->scalar("SELECT id FROM supplier_documents WHERE category = 'gst_certificate'");
        $dl = $b->get('/suppliers/' . $this->alpha . '/documents/' . $doc . '?download=1');
        $this->assertSame(200, $dl['status']);
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action LIKE 'supplier.document_%'"), 'document access logged');
        $this->assertSame(200, $b->get('/dashboard')['status']);
    }
}
