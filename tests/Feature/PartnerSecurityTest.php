<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Admin\PermissionService;
use App\Services\Partners\CapitalService;
use App\Services\Partners\PartnerService;
use App\Support\Money;

/** Phase 3 security through real HTTP requests: record scope, field rules, documents, exports. */
final class PartnerSecurityTest extends HttpTestCase
{
    private array $root;
    private int $a;
    private int $b;
    private int $txnA;
    private int $txnB;

    public function setUp(): void
    {
        parent::setUp();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $ps = new PartnerService(App::db());
        $this->a = $ps->create(['name' => 'Alpha Partner', 'date_of_joining' => '2025-04-01', 'pan_number' => 'AAAPA1234Z', 'email' => 'alpha@example.com'], $this->root);
        $this->b = $ps->create(['name' => 'Bravo Partner', 'date_of_joining' => '2025-04-01', 'email' => 'bravo@example.com'], $this->root);
        $this->txnA = $this->post($this->a, '500000');
        $this->txnB = $this->post($this->b, '300000');
        $login = $this->makeUser('alpha.login@example.com', ['partner'], name: 'Alpha Login');
        $ps->link($ps->find($this->a), (int) $login['id'], $this->root);
    }

    private function post(int $partner, string $amount): int
    {
        $cs = new CapitalService(App::db());
        $id = $cs->createDraft('contribution', ['partner_id' => $partner, 'amount' => Money::parse($amount), 'transaction_date' => '2026-01-10', 'payment_method' => 'bank_transfer', 'payment_reference' => 'UTR' . $partner . '99887766'], $this->root);
        $cs->submit($cs->find($id), $this->root);
        $cs->post($cs->find($id), $this->root);
        return $id;
    }

    private function pid(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM permissions WHERE slug = ?', [$slug]);
    }

    private function fieldId(string $module, string $field): int
    {
        return (int) App::db()->scalar('SELECT f.id FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE m.module_key = ? AND f.field_key = ?', [$module, $field]);
    }

    private function roleId(string $slug): int
    {
        return (int) App::db()->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
    }

    public function testPartnerLoginSeesOnlyItsOwnPartnerAndCapital(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'alpha.login@example.com');
        $list = $b->get('/partners');
        $this->assertSame(200, $list['status']);
        $this->assertContains('Alpha Partner', $list['body']);
        $this->assertNotContains('Bravo Partner', $list['body']);
        $this->assertSame(200, $b->get('/partners/' . $this->a)['status']);
        $this->assertSame(404, $b->get('/partners/' . $this->b)['status'], 'other partner hidden as 404');
        $this->assertSame(200, $b->get('/capital/contributions/' . $this->txnA)['status']);
        $this->assertSame(404, $b->get('/capital/contributions/' . $this->txnB)['status']);
        $this->assertSame(404, $b->get('/capital/ledger/' . $this->b)['status']);
        $ledger = $b->get('/capital/ledger/' . $this->a);
        $this->assertContains('5,00,000.00', $ledger['body'], 'own capital balance visible');
        $contrib = $b->get('/capital/contributions');
        $this->assertContains('CC-2026-00001', $contrib['body']);
        $this->assertNotContains('CC-2026-00002', $contrib['body']);
        // No access to configuration, other modules, posting or self-service linking.
        $this->assertSame(403, $b->get('/profit-sharing')['status']);
        $this->assertSame(403, $b->get('/admin')['status']);
        $this->assertSame(403, $b->post('/capital/contributions/' . $this->txnA . '/reverse', ['reason' => 'mine now', 'reversal_date' => '2026-01-11'])['status']);
        $this->assertSame(403, $b->post('/partners/' . $this->b . '/link', ['user_id' => 1])['status']);
        $this->assertSame(403, $b->get('/partners/export?format=csv')['status']);
        $this->assertSame('posted', App::db()->scalar('SELECT status FROM partner_capital_transactions WHERE id = ?', [$this->txnA]));
        // Dashboard figures are limited to the partner's own capital.
        $dash = $b->get('/dashboard');
        $this->assertContains('5,00,000.00', $dash['body']);
        $this->assertNotContains('8,00,000.00', $dash['body'], 'firm-wide total not shown');
    }

    public function testHiddenAndMaskedFieldsNeverReachPagesOrExports(): void
    {
        $perm = new PermissionService(App::db());
        $acc = $this->makeUser('acc@example.com', ['accountant'], name: 'Accountant');
        $perm->setUserDataScope((int) $acc['id'], 'capital_contributions', 'all', [], $this->root);
        $perm->setUserDataScope((int) $acc['id'], 'partners', 'all', [], $this->root);
        $perm->setUserOverrides((int) $acc['id'], [$this->pid('capital_contributions.export') => 'allow'], $this->root);
        $perm->setRoleFieldAccess($this->roleId('accountant'), [
            $this->fieldId('capital_contributions', 'amount') => 'hidden',
            $this->fieldId('capital_contributions', 'payment_reference') => 'masked',
            $this->fieldId('partners', 'capital_balance') => 'hidden',
        ], $this->root);
        $b = $this->browser();
        $this->signIn($b, 'acc@example.com');
        $page = $b->get('/capital/contributions');
        $this->assertSame(200, $page['status']);
        $this->assertContains('CC-2026-00002', $page['body']);
        $this->assertNotContains('5,00,000', $page['body'], 'hidden amount not rendered');
        $show = $b->get('/capital/contributions/' . $this->txnA);
        $this->assertNotContains('5,00,000', $show['body']);
        $this->assertNotContains('UTR199887766', $show['body'], 'masked reference');
        $csv = $b->get('/capital/contributions/export?format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertNotContains('Amount', $csv['body'], 'hidden column removed from export header');
        $this->assertNotContains('500000.00', $csv['body']);
        $this->assertNotContains('UTR199887766', $csv['body']);
        $this->assertContains('CC-2026-00001', $csv['body']);
        $list = $b->get('/partners');
        $this->assertNotContains('5,00,000', $list['body'], 'hidden capital balance on partner list');
        // Users who cannot see the real amount cannot create entries blind.
        $this->assertSame(403, $b->get('/capital/contributions/create')['status']);

        // Manager: partner directory only, PAN masked, no financial columns, no profit sharing.
        $this->makeUser('mgr@example.com', ['manager'], name: 'Manager');
        $m = $this->browser();
        $this->signIn($m, 'mgr@example.com');
        $profile = $m->get('/partners/' . $this->a . '?tab=personal');
        $this->assertSame(200, $profile['status']);
        $this->assertNotContains('AAAPA1234Z', $profile['body'], 'PAN masked by default');
        $this->assertContains('234Z', $profile['body']);
        $this->assertNotContains('Capital balance', $m->get('/partners')['body']);
        $this->assertSame(403, $m->get('/profit-sharing')['status']);
        $this->assertSame(403, $m->get('/capital/contributions')['status']);
    }

    public function testDocumentsArePrivateValidatedAndPermissionChecked(): void
    {
        $pdf = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($pdf, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n");
        $root = $this->browser();
        $this->signIn($root, 'root@example.com');
        $root->get('/partners/' . $this->a . '?tab=documents');
        $up = $root->upload('/partners/' . $this->a . '/documents', ['category' => 'agreement', 'title' => 'Deed'], ['document' => [$pdf, 'deed.pdf', 'application/pdf']]);
        $this->assertSame(302, $up['status']);
        $doc = App::db()->first('SELECT * FROM partner_documents ORDER BY id DESC LIMIT 1');
        $this->assertSame('deed.pdf', $doc['original_name']);
        $this->assertTrue(preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.pdf$#', (string) $doc['stored_name']) === 1, 'random stored name');
        $this->assertFalse(is_file(App::basePath('public/' . $doc['stored_name'])), 'not under the web root');
        $this->assertTrue(is_file(App::basePath('storage/uploads/partner-documents/' . $doc['stored_name'])));

        $dl = $root->get('/partners/' . $this->a . '/documents/' . $doc['id']);
        $this->assertSame(200, $dl['status']);
        $this->assertContains('%PDF-1.4', $dl['body']);
        $this->assertContains('X-Content-Type-Options: nosniff', $dl['headers']);
        $this->assertContains('attachment', $dl['headers']);
        $this->assertSame(404, $root->get('/partners/' . $this->b . '/documents/' . $doc['id'])['status'], 'document must belong to the partner in the URL');

        // Disguised script and wrong-type uploads are rejected.
        $php = tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($php, "<?php echo 'pwned';");
        $root->get('/partners/' . $this->a . '?tab=documents');
        $root->upload('/partners/' . $this->a . '/documents', ['category' => 'other'], ['document' => [$php, 'invoice.pdf', 'application/pdf']]);
        $root->get('/partners/' . $this->a . '?tab=documents');
        $root->upload('/partners/' . $this->a . '/documents', ['category' => 'other'], ['document' => [$php, 'shell.php', 'application/x-php']]);
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM partner_documents'), 'nothing stored for invalid files');

        // The linked partner can read their own documents; others cannot.
        $p = $this->browser();
        $this->signIn($p, 'alpha.login@example.com');
        $this->assertSame(200, $p->get('/partners/' . $this->a . '/documents/' . $doc['id'])['status']);
        $this->makeUser('viewer@example.com', ['viewer']);
        $v = $this->browser();
        $this->signIn($v, 'viewer@example.com');
        $this->assertSame(403, $v->get('/partners/' . $this->a . '/documents/' . $doc['id'])['status']);
        $guest = $this->browser();
        $g = $guest->get('/partners/' . $this->a . '/documents/' . $doc['id']);
        $this->assertSame(302, $g['status']);
        $this->assertContains('/login', $g['location']);
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'partner.document_downloaded'"), 'downloads are logged');

        // Identity documents follow the identity_documents field rule.
        $root->get('/partners/' . $this->a . '?tab=documents');
        $root->upload('/partners/' . $this->a . '/documents', ['category' => 'pan', 'title' => 'PAN card'], ['document' => [$pdf, 'pan.pdf', 'application/pdf']]);
        $panDoc = (int) App::db()->scalar("SELECT id FROM partner_documents WHERE category = 'pan'");
        (new PermissionService(App::db()))->setRoleFieldAccess($this->roleId('partner'), [$this->fieldId('partners', 'identity_documents') => 'hidden'], $this->root);
        $this->assertSame(404, $p->get('/partners/' . $this->a . '/documents/' . $panDoc)['status']);
        $this->assertNotContains('PAN card', $p->get('/partners/' . $this->a . '?tab=documents')['body']);
    }

    public function testReportsAndExportsRespectRecordScope(): void
    {
        $login = (int) App::db()->scalar("SELECT id FROM users WHERE email = 'alpha.login@example.com'");
        (new PermissionService(App::db()))->setUserOverrides($login, [
            $this->pid('partner_reports.view') => 'allow', $this->pid('partner_reports.export') => 'allow', $this->pid('capital_contributions.export') => 'allow',
        ], $this->root);
        $b = $this->browser();
        $this->signIn($b, 'alpha.login@example.com');
        $page = $b->get('/partner-reports/contributions');
        $this->assertSame(200, $page['status']);
        $this->assertContains('CC-2026-00001', $page['body']);
        $this->assertNotContains('CC-2026-00002', $page['body']);
        $csv = $b->get('/partner-reports/contributions/export?format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertContains('CC-2026-00001', $csv['body']);
        $this->assertNotContains('Bravo', $csv['body']);
        $xlsx = $b->get('/partner-reports/contributions/export?format=xlsx');
        $this->assertSame("PK\x03\x04", substr($xlsx['body'], 0, 4), 'valid xlsx (zip)');
        $this->assertNotContains('Bravo', (string) $this->unzipSheet($xlsx['body']));
        $pdf = $b->get('/partner-reports/contributions/export?format=pdf');
        $this->assertSame('%PDF-', substr($pdf['body'], 0, 5));
        $this->assertNotContains('Bravo', $pdf['body']);
        $this->assertSame(403, $b->get('/partner-reports/withdrawals/export?format=csv')['status'], 'module export permission required too');
        $this->assertSame(404, $b->get('/partner-reports/ledger?partner=' . $this->b)['status']);
        $this->assertSame(3, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'partner_reports.exported' AND user_id = ?", [$login]));
    }

    public function testCapitalWorkflowThroughHttpIsAuditedWithActorAndIp(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/capital/withdrawals/create?partner=' . $this->a);
        $r = $b->post('/capital/withdrawals', ['partner_id' => $this->a, 'amount' => '600000', 'transaction_date' => '2026-02-01', 'payment_method' => 'cash', 'reason' => 'Too much', 'then' => 'submit']);
        $this->assertSame(302, $r['status']);
        $id = (int) App::db()->scalar("SELECT id FROM partner_capital_transactions WHERE type = 'withdrawal'");
        $this->assertSame('draft', App::db()->scalar('SELECT status FROM partner_capital_transactions WHERE id = ?', [$id]), 'over-limit submission blocked, draft kept');
        $b->get('/capital/withdrawals/' . $id . '/edit');
        $b->post('/capital/withdrawals/' . $id, ['partner_id' => $this->a, 'amount' => '100000.50', 'transaction_date' => '2026-02-01', 'payment_method' => 'cash', 'reason' => 'Drawings']);
        $b->get('/capital/withdrawals/' . $id);
        $b->post('/capital/withdrawals/' . $id . '/submit');
        $b->get('/capital/withdrawals/' . $id);
        $b->post('/capital/withdrawals/' . $id . '/post');
        $b->get('/capital/withdrawals/' . $id);
        $this->assertSame(302, $b->post('/capital/withdrawals/' . $id . '/post')['status']);
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM accounting_posting_queue WHERE source_id = ? AND event = 'capital.withdrawal.posted'", [$id]), 'posted exactly once');
        $this->assertSame(Money::parse('399999.50'), App::capitalLedger()->balance($this->a));
        $log = App::db()->first("SELECT * FROM activity_logs WHERE action = 'capital.posted' AND subject_id = ?", [$id]);
        $this->assertSame((int) $this->root['id'], (int) $log['user_id']);
        $this->assertSame('127.0.0.1', $log['ip_address']);
        $this->assertSame('capital_withdrawals', $log['module']);
        // Amount strings with commas or 3 decimals are rejected, never rounded silently.
        $b->get('/capital/contributions/create');
        $b->post('/capital/contributions', ['partner_id' => $this->a, 'amount' => '1,000.999', 'transaction_date' => '2026-02-01', 'payment_method' => 'cash']);
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM partner_capital_transactions WHERE type = 'contribution'"));
    }

    private function unzipSheet(string $xlsx): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $xlsx);
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $s = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        @unlink($tmp);
        return $s;
    }
}
