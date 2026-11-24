<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Gst\GstDocumentService;
use App\Services\Gst\GstRegistrationService;
use App\Services\Gst\GstRelationService;
use App\Services\Partners\PartnerService;
use Tests\Integration\GstRegistrationTest;

/** Phase 4 security through real HTTP requests: record scope, field rules, documents, exports, permissions. */
final class GstSecurityTest extends HttpTestCase
{
    private array $root;
    private int $a;
    private int $b;
    private string $gA;
    private string $gB;
    private int $docA;

    public function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $ps = new PartnerService($db);
        $alpha = $ps->create(['name' => 'Alpha Partner', 'date_of_joining' => '2025-04-01'], $this->root);
        $ps->create(['name' => 'Bravo Partner', 'date_of_joining' => '2025-04-01'], $this->root);
        $login = $this->makeUser('alpha.login@example.com', ['partner'], name: 'Alpha Login');
        $ps->link($ps->find($alpha), (int) $login['id'], $this->root);

        $gs = new GstRegistrationService($db);
        $this->gA = GstRegistrationTest::g('24AAKFM4821R1Z');
        $this->gB = GstRegistrationTest::g('27BKNPN5678L1Z');
        $reg = fn (string $g, string $state, string $name): int => $gs->create([
            'gstin' => $g, 'legal_name' => $name, 'registration_type_id' => (int) $db->scalar("SELECT id FROM gst_registration_types WHERE code = 'regular'"), 'status' => 'active',
            'contact_phone' => '9825012340', 'addr_line1' => '1 Main Road', 'addr_city' => 'City', 'addr_state_id' => (int) $db->scalar('SELECT id FROM gst_states WHERE code = ?', [$state]), 'addr_pin_code' => '380001',
        ], $this->root)[0];
        $this->a = $reg($this->gA, '24', 'Alpha Traders');
        $this->b = $reg($this->gB, '27', 'Bravo Stores');
        (new GstRelationService($db))->addPartner($gs->find($this->a), ['partner_id' => $alpha, 'relationship_type_id' => (int) $db->scalar("SELECT id FROM gst_partner_relationship_types WHERE code = 'partner'"), 'effective_from' => '2025-04-01'], $this->root);
        $tmp = tempnam(sys_get_temp_dir(), 'gst');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF\n");
        $this->docA = (new GstDocumentService($db))->upload($gs->find($this->a), ['name' => 'REG-06.pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)], ['document_type' => 'registration_certificate'], $this->root, false);

        $acct = $this->makeUser('acct@example.com', ['accountant'], name: 'Accountant');
        (new ResourceAssignmentService($db))->sync((int) $acct['id'], 'gst_registration', [$this->b], $this->root);
        $this->makeUser('viewer@example.com', ['viewer'], name: 'Viewer');
        $this->makeUser('manager@example.com', ['manager'], name: 'Manager');
    }

    private function fieldId(string $field): int
    {
        return (int) App::db()->scalar("SELECT f.id FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE m.module_key = 'gst_registrations' AND f.field_key = ?", [$field]);
    }

    public function testPartnerLoginSeesOnlyMappedRegistrations(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'alpha.login@example.com');
        $list = $b->get('/gst/registrations');
        $this->assertSame(200, $list['status']);
        $this->assertContains($this->gA, $list['body']);
        $this->assertNotContains($this->gB, $list['body']);
        $this->assertSame(200, $b->get('/gst/registrations/' . $this->a)['status']);
        $this->assertSame(404, $b->get('/gst/registrations/' . $this->b)['status'], 'unmapped registration hidden as 404');
        $this->assertSame(200, $b->get('/gst/documents/' . $this->docA . '/download')['status'], 'own certificate downloadable');
        $this->assertSame(403, $b->get('/gst/registrations/create')['status']);
        $b->get('/dashboard');
        $this->assertSame(403, $b->post('/gst/registrations/' . $this->a . '/status', ['status' => 'inactive'])['status']);
        $this->assertSame(403, $b->get('/gst/masters')['status']);
        $this->assertContains($this->gA, $b->get('/partners')['body'] . $b->get('/gst')['body']);
    }

    public function testAccountantSeesOnlyAssignedRegistrations(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'acct@example.com');
        $list = $b->get('/gst/registrations');
        $this->assertContains($this->gB, $list['body']);
        $this->assertNotContains($this->gA, $list['body']);
        $this->assertSame(404, $b->get('/gst/registrations/' . $this->a)['status']);
        $this->assertSame(404, $b->get('/gst/documents/' . $this->docA . '/download')['status'], 'documents of unassigned registrations are hidden');
        $csv = $b->get('/gst/reports/all/export?format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertContains($this->gB, $csv['body']);
        $this->assertNotContains($this->gA, $csv['body'], 'exports are record-scoped');
        $this->assertNotContains($this->gA, $b->get('/gst/reports/state_wise')['body'] . $b->get('/gst')['body']);
    }

    public function testViewerWithoutPermissionIsDenied(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'viewer@example.com');
        foreach (['/gst', '/gst/registrations', '/gst/registrations/' . $this->a, '/gst/reports', '/gst/documents/' . $this->docA . '/download'] as $url) {
            $this->assertSame(403, $b->get($url)['status'], $url);
        }
        $b->get('/dashboard');
        $this->assertSame(403, $b->post('/gst/check-gstin', ['gstin' => $this->gA])['status']);
    }

    public function testManagerCanViewAllButNotChangeOrExport(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'manager@example.com');
        $list = $b->get('/gst/registrations');
        $this->assertContains($this->gA, $list['body']);
        $this->assertContains($this->gB, $list['body']);
        $this->assertSame(403, $b->get('/gst/registrations/' . $this->a . '/edit')['status']);
        $b->get('/dashboard');
        $this->assertSame(403, $b->post('/gst/registrations/' . $this->a . '/archive', ['reason' => 'x'])['status']);
        $this->assertSame(403, $b->get('/gst/registrations/export?format=csv')['status']);
    }

    public function testFieldRulesMaskAndHideInPagesAndExports(): void
    {
        (new PermissionService(App::db()))->setUserFieldAccess((int) App::db()->scalar("SELECT id FROM users WHERE email = 'acct@example.com'"),
            [$this->fieldId('pan') => 'masked', $this->fieldId('contact_phone') => 'hidden'], $this->root);
        $b = $this->browser();
        $this->signIn($b, 'acct@example.com');
        $page = $b->get('/gst/registrations/' . $this->b . '?tab=details');
        // The GSTIN itself embeds the PAN; the PAN field on its own must be masked.
        $this->assertNotContains('BKNPN5678L', str_replace($this->gB, '', $page['body']), 'PAN masked');
        $this->assertContains('678L', $page['body'], 'last four shown');
        $this->assertNotContains('9825012340', $page['body'], 'hidden contact number not rendered');
        $csv = $b->get('/gst/reports/all/export?format=csv')['body'];
        $this->assertNotContains('BKNPN5678L', str_replace($this->gB, '', $csv));
        $this->assertNotContains('9825012340', $csv);
    }

    public function testAdminFlowsServerSideValidationAndCsrf(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $this->assertSame(419, $b->post('/gst/registrations/' . $this->a . '/status', ['status' => 'inactive', '_csrf' => 'wrong'], false)['status']);
        $b->get('/dashboard');
        $dup = $b->post('/gst/check-gstin', ['gstin' => strtolower($this->gA)]);
        $json = json_decode($dup['body'], true);
        $this->assertFalse($json['valid']);
        $this->assertContains('already registered', implode(' ', $json['errors']));
        $this->assertContains('does not confirm', $json['notice'], 'never claims official verification');
        $b->get('/gst/registrations/' . $this->a);
        $r = $b->post('/gst/registrations/' . $this->a . '/status', ['status' => 'suspended', 'reason' => 'Notice received']);
        $this->assertTrue(in_array($r['status'], [200, 302, 303], true), 'status change accepted: HTTP ' . $r['status']);
        $this->assertSame('suspended', (string) App::db()->scalar('SELECT status FROM gst_registrations WHERE id = ?', [$this->a]));
        foreach (['overview', 'details', 'partners', 'activities', 'locations', 'documents', 'settings', 'history'] as $tab) {
            $this->assertSame(200, $b->get('/gst/registrations/' . $this->a . '?tab=' . $tab)['status'], $tab);
        }
        foreach (['all', 'active', 'cancelled_suspended', 'state_wise', 'type_summary', 'partner_wise', 'recently_updated'] as $report) {
            $this->assertSame(200, $b->get('/gst/reports/' . $report)['status'], $report);
            foreach (['csv', 'xlsx', 'pdf'] as $f) {
                $this->assertSame(200, $b->get("/gst/reports/{$report}/export?format={$f}")['status'], "{$report}.{$f}");
            }
        }
        $this->assertSame(200, $b->get('/gst')['status']);
        $this->assertSame(200, $b->get('/partners/1?tab=gst')['status']);
    }
}
