<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Ecommerce\PlatformDocumentService;
use App\Services\Ecommerce\PlatformNoteService;
use App\Services\Ecommerce\PlatformService;
use App\Services\Product\DatedMappingService;

/** Phase 8A through real HTTP requests: every page renders, permissions, record scope, field rules, documents, export and CSRF. */
final class EcommerceSecurityTest extends HttpTestCase
{
    private array $root;
    private int $amz;
    private int $fk;
    private int $doc;

    public function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $svc = new PlatformService($db);
        $cat = static fn (string $c): int => (int) $db->scalar('SELECT id FROM ecommerce_platform_categories WHERE code = ?', [$c]);
        $this->amz = $svc->create(['name' => 'Amazon India', 'category_id' => $cat('marketplace'), 'website_url' => 'https://sellercentral.amazon.in', 'internal_notes' => 'Top secret commission deal'], $this->root);
        $this->fk = $svc->create(['name' => 'Flipkart', 'category_id' => $cat('marketplace')], $this->root);
        $svc->changeStatus($svc->find($this->amz), 'active', '', $this->root);
        $wh = (new \App\Services\Inventory\WarehouseService($db))->create(['name' => 'Ahmedabad Central', 'warehouse_type_id' => (int) $db->scalar("SELECT id FROM warehouse_types WHERE code = 'main'")], $this->root);
        (new DatedMappingService($db, 'platform_warehouse'))->add($svc->find($this->amz), $wh, [], $this->root);
        $pdf = sys_get_temp_dir() . '/p8h-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->doc = (new PlatformDocumentService($db))->upload($svc->find($this->amz), ['name' => 'agreement.pdf', 'tmp_name' => $pdf, 'error' => 0, 'size' => filesize($pdf)],
            ['document_type' => 'agreement', 'title' => 'Seller agreement'], $this->root, false);
        (new PlatformNoteService($db))->save($svc->find($this->amz), ['title' => 'Private plan', 'body' => 'Hidden from others', 'visibility' => 'private'], null, $this->root);
        $this->makeUser('acct@example.com', ['accountant'], name: 'Accountant');
        $this->makeUser('manager@example.com', ['manager'], name: 'Manager');
        $this->makeUser('viewer@example.com', ['viewer'], name: 'Viewer');
        $desk = $this->makeUser('desk@example.com', ['manager'], name: 'Flipkart Desk');
        $perm = static fn (string $s): int => (int) $db->scalar('SELECT id FROM permissions WHERE slug = ?', [$s]);
        (new PermissionService($db))->setUserOverrides((int) $desk['id'], [$perm('platforms.view_all') => 'deny', $perm('platforms.view_assigned') => 'allow'], $this->root, null, 'Flipkart only');
        (new ResourceAssignmentService($db))->sync((int) $desk['id'], 'platform', [$this->fk], $this->root);
    }

    /** @return list<string> */
    private function pages(): array
    {
        $pages = ['/ecommerce', '/ecommerce?status=active&category=1&from=2026-01-01', '/ecommerce/platforms', '/ecommerce/platforms?status=archived', '/ecommerce/platforms?q=amazon&connection=not_connected',
            '/ecommerce/platforms/create', '/ecommerce/platforms/' . $this->amz . '/edit', '/ecommerce/categories', '/ecommerce/categories?edit=1', '/ecommerce/history',
            '/ecommerce/history?platform=' . $this->amz . '&event=created', '/ecommerce/platforms/' . $this->amz . '?tab=notes&archived=1', '/ecommerce/platforms/' . $this->amz . '?tab=documents&archived=1'];
        foreach (array_keys(\App\Controllers\Ecommerce\PlatformController::TABS) as $tab) {
            $pages[] = '/ecommerce/platforms/' . $this->amz . '?tab=' . $tab;
            $pages[] = '/ecommerce/platforms/' . $this->fk . '?tab=' . $tab;
        }
        return $pages;
    }

    public function testEveryPageRendersForSuperAdminAndExportWorks(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        foreach ($this->pages() as $url) {
            $r = $b->get($url);
            $this->assertSame(200, $r['status'], $url . ' → ' . mb_substr(strip_tags($r['body']), 0, 300));
            foreach (['Fatal error', 'Warning:', 'Notice:', 'Deprecated:', 'Uncaught', 'Stack trace', ' style="'] as $bad) {
                $this->assertNotContains($bad, $r['body'], "{$url} contains {$bad}");
            }
        }
        $this->assertContains('Top secret commission deal', $b->get('/ecommerce/platforms/' . $this->amz)['body']);
        $this->assertContains('Private plan', $b->get('/ecommerce/platforms/' . $this->amz . '?tab=notes')['body']);
        $csv = $b->get('/ecommerce/platforms/export?format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertContains('Amazon India', $csv['body']);
        $this->assertContains('Flipkart', $csv['body']);
        $this->assertSame(200, $b->get('/ecommerce/platforms/export?format=xlsx')['status']);
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'platforms.exported' AND description LIKE '%2 rows%'"));
        // Add a platform and upload a document through the forms.
        $b->get('/ecommerce/platforms/create');
        $r = $b->post('/ecommerce/platforms', ['name' => 'Meesho', 'category_id' => (string) App::db()->scalar("SELECT id FROM ecommerce_platform_categories WHERE code = 'marketplace'"), 'country' => 'IN', 'default_currency' => 'INR']);
        $this->assertTrue(in_array($r['status'], [302, 303], true));
        $id = (int) App::db()->scalar("SELECT id FROM ecommerce_platforms WHERE name = 'Meesho'");
        $b->get('/ecommerce/platforms/' . $id . '?tab=configuration');
        $b->post('/ecommerce/platforms/' . $id . '/configuration', ['currencies' => ['INR'], 'default_currency' => 'INR', 'languages' => ['en', 'hi'], 'time_zone' => 'Asia/Kolkata', 'date_format' => 'd-m-Y', 'number_format' => 'en_IN', 'country' => 'IN', 'reference_prefix' => 'MSH']);
        $this->assertSame('MSH', App::db()->scalar('SELECT reference_prefix FROM ecommerce_platforms WHERE id = ?', [$id]));
        $b->post('/ecommerce/platforms/' . $id . '/status', ['status' => 'active']);
        $this->assertSame('active', App::db()->scalar('SELECT status FROM ecommerce_platforms WHERE id = ?', [$id]));
        $pdf = sys_get_temp_dir() . '/p8u-' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $b->get('/ecommerce/platforms/' . $id . '?tab=documents');
        $r = $b->upload('/ecommerce/platforms/' . $id . '/documents', ['title' => 'Fee schedule', 'document_type' => 'fee_schedule'], ['file' => [$pdf, 'fees.pdf', 'application/pdf']]);
        $this->assertTrue(in_array($r['status'], [302, 303], true));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM ecommerce_platform_documents WHERE platform_id = ? AND document_type = 'fee_schedule'", [$id]));
    }

    public function testPermissionsAndReadOnlyRoles(): void
    {
        $v = $this->browser();
        $this->signIn($v, 'viewer@example.com');
        foreach (['/ecommerce', '/ecommerce/platforms', '/ecommerce/platforms/' . $this->amz, '/ecommerce/platforms/export?format=csv', '/ecommerce/history', '/ecommerce/categories',
            '/ecommerce/platforms/' . $this->amz . '/documents/' . $this->doc] as $url) {
            $this->assertSame(403, $v->get($url)['status'], $url);
        }
        $a = $this->browser();
        $this->signIn($a, 'acct@example.com');
        $this->assertSame(200, $a->get('/ecommerce')['status']);
        $show = $a->get('/ecommerce/platforms/' . $this->amz)['body'];
        $this->assertNotContains('Top secret commission deal', $show, 'internal notes hidden by default');
        $this->assertNotContains('Private plan', $a->get('/ecommerce/platforms/' . $this->amz . '?tab=notes')['body'], 'private notes hidden');
        $this->assertNotContains('Top secret', $a->get('/ecommerce/platforms/export?format=csv')['body'], 'exports respect field rules');
        $this->assertSame(200, $a->get('/ecommerce/platforms/' . $this->amz . '/documents/' . $this->doc)['status'], 'accountant may download');
        $this->assertSame(403, $a->get('/ecommerce/platforms/create')['status']);
        $a->get('/ecommerce/platforms');
        $this->assertSame(403, $a->post('/ecommerce/platforms', ['name' => 'X'])['status']);
        $this->assertSame(403, $a->post('/ecommerce/platforms/' . $this->amz . '/status', ['status' => 'inactive', 'reason' => 'x'])['status']);
        $this->assertSame(403, $a->post('/ecommerce/platforms/' . $this->amz . '/archive', ['reason' => 'x', 'confirm' => '1'])['status']);
        $this->assertSame(403, $a->post('/ecommerce/platforms/' . $this->amz . '/mappings/gst', ['gst_registration_id' => 1])['status']);
        $this->assertSame(403, $a->post('/ecommerce/categories', ['name' => 'Hack'])['status']);
        $this->assertSame('active', App::db()->scalar('SELECT status FROM ecommerce_platforms WHERE id = ?', [$this->amz]));
        // Manager: maintains platforms and warehouse mappings, but cannot activate, archive or map GST registrations.
        $m = $this->browser();
        $this->signIn($m, 'manager@example.com');
        $this->assertSame(200, $m->get('/ecommerce/platforms/create')['status']);
        $m->get('/ecommerce/platforms');
        $this->assertSame(403, $m->post('/ecommerce/platforms/' . $this->fk . '/status', ['status' => 'active'])['status']);
        $this->assertSame(403, $m->post('/ecommerce/platforms/' . $this->fk . '/mappings/gst', ['gst_registration_id' => 1])['status']);
        $this->assertSame(403, $m->post('/ecommerce/platforms/' . $this->fk . '/mappings/partners', ['partner_id' => 1])['status']);
        $this->assertSame('under_configuration', App::db()->scalar('SELECT status FROM ecommerce_platforms WHERE id = ?', [$this->fk]));
        // CSRF is enforced.
        $this->assertSame(419, $m->post('/ecommerce/platforms/' . $this->fk . '/settings', ['setting_key' => 'a_b', 'value' => '1'], false)['status']);
    }

    public function testRecordScopeAndDocumentAccess(): void
    {
        $d = $this->browser();
        $this->signIn($d, 'desk@example.com');
        $list = $d->get('/ecommerce/platforms')['body'];
        $this->assertContains('Flipkart', $list);
        $this->assertNotContains('Amazon India', $list);
        $this->assertNotContains('Amazon India', $d->get('/ecommerce')['body'], 'dashboard is scoped');
        $this->assertNotContains('Amazon India', $d->get('/ecommerce/platforms/export?format=csv')['body'], 'export is scoped');
        $this->assertNotContains('Amazon India', $d->get('/ecommerce/history')['body'], 'history is scoped');
        foreach (['', '?tab=documents', '/edit', '/logo', '/documents/' . $this->doc] as $suffix) {
            $this->assertSame(404, $d->get('/ecommerce/platforms/' . $this->amz . $suffix)['status'], 'outside the scope: ' . $suffix);
        }
        $d->get('/ecommerce/platforms/' . $this->fk);
        $this->assertSame(404, $d->post('/ecommerce/platforms/' . $this->amz, ['name' => 'Hijack', 'category_id' => 1])['status']);
        // A document id requested through another platform is not served.
        $this->assertSame(404, $d->get('/ecommerce/platforms/' . $this->fk . '/documents/' . $this->doc)['status']);
        $this->assertSame('Amazon India', App::db()->scalar('SELECT name FROM ecommerce_platforms WHERE id = ?', [$this->amz]));
        // Documents are never in the public folder.
        $stored = (string) App::db()->scalar('SELECT stored_name FROM ecommerce_platform_documents WHERE id = ?', [$this->doc]);
        $this->assertFalse(is_file(App::basePath('public/' . $stored)));
        $this->assertNotSame(200, $d->get('/storage/uploads/platform-documents/' . $stored)['status']);
    }
}
