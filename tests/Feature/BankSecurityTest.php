<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\App;
use App\Services\Admin\PermissionService;
use App\Services\Admin\ResourceAssignmentService;
use App\Services\Bank\BankAccountService;
use App\Services\Bank\BankRelationService;
use App\Services\Bank\BankTransactionService;
use App\Services\Partners\PartnerService;

/** Phase 5 security through real HTTP requests: record and field scope, role and user permissions, exports, CSRF, documents. */
final class BankSecurityTest extends HttpTestCase
{
    private array $root;
    private int $a;
    private int $b;

    public function setUp(): void
    {
        parent::setUp();
        $db = App::db();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $ps = new PartnerService($db);
        $alpha = $ps->create(['name' => 'Alpha Partner', 'date_of_joining' => '2025-04-01'], $this->root);
        $login = $this->makeUser('alpha.login@example.com', ['partner'], name: 'Alpha Login');
        $ps->link($ps->find($alpha), (int) $login['id'], $this->root);
        $accounts = new BankAccountService($db);
        $type = (int) $db->scalar("SELECT id FROM bank_account_types WHERE code = 'current'");
        $mk = fn (string $name, string $no, string $opening): int => $accounts->create(['display_name' => $name, 'bank_name' => 'HDFC Bank', 'account_holder_name' => 'Mehta Traders', 'account_number' => $no,
            'ifsc_code' => 'HDFC0001234', 'account_type_id' => $type, 'opening_balance' => $opening, 'opening_balance_date' => '2025-04-01', 'internal_notes' => 'Operated by Rohan'], $this->root, true)[0];
        $this->a = $mk('Alpha operations', '50100111122223333', '50000');
        $this->b = $mk('Bravo stores', '50100999988887777', '80000');
        (new BankRelationService($db))->addPartner($accounts->find($this->a), ['partner_id' => $alpha, 'relationship' => 'authorized_operator', 'effective_from' => '2025-04-01'], $this->root);
        (new BankTransactionService($db))->create(['bank_account_id' => $this->b, 'type' => 'deposit', 'amount' => '1234.56', 'transaction_date' => '2026-01-10', 'counterparty_name' => 'Secret Customer Pvt Ltd'], $this->root, true);
        $acct = $this->makeUser('acct@example.com', ['accountant'], name: 'Accountant');
        (new ResourceAssignmentService($db))->sync((int) $acct['id'], 'bank_account', [$this->b], $this->root);
        $this->makeUser('viewer@example.com', ['viewer'], name: 'Viewer');
        $this->makeUser('manager@example.com', ['manager'], name: 'Manager');
    }

    private function fieldId(string $field): int
    {
        return (int) App::db()->scalar("SELECT f.id FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE m.module_key = 'bank_accounts' AND f.field_key = ?", [$field]);
    }

    public function testAccountantSeesOnlyAssignedAccountsEverywhere(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'acct@example.com');
        $list = $b->get('/bank/accounts');
        $this->assertContains('Bravo stores', $list['body']);
        $this->assertNotContains('Alpha operations', $list['body']);
        $this->assertSame(404, $b->get('/bank/accounts/' . $this->a)['status'], 'unassigned account hidden as 404');
        $this->assertSame(200, $b->get('/bank/accounts/' . $this->b . '?tab=transactions')['status']);
        $this->assertNotContains('Alpha operations', $b->get('/bank')['body'] . $b->get('/bank/transactions')['body']);
        $csv = $b->get('/bank/reports/summary/export?format=csv');
        $this->assertSame(200, $csv['status']);
        $this->assertContains('Bravo stores', $csv['body']);
        $this->assertNotContains('Alpha operations', $csv['body'], 'exports are record-scoped');
        // Can record a deposit on the assigned account but not approve, reverse, transfer or see full numbers.
        $b->get('/bank/transactions/create?type=deposit');
        $r = $b->post('/bank/transactions', ['bank_account_id' => $this->b, 'type' => 'deposit', 'amount' => '500', 'transaction_date' => '2026-01-11', 'action' => 'draft']);
        $this->assertTrue(in_array($r['status'], [200, 302, 303], true));
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM bank_transactions WHERE status = 'draft' AND bank_account_id = ?", [$this->b]));
        $b->get('/bank/transactions/create?type=deposit');
        $this->assertSame(404, $b->post('/bank/transactions', ['bank_account_id' => $this->a, 'type' => 'deposit', 'amount' => '500', 'transaction_date' => '2026-01-11'])['status'], 'cannot record on another account');
        $id = (int) App::db()->scalar("SELECT id FROM bank_transactions WHERE status = 'posted' AND bank_account_id = ?", [$this->b]);
        $b->get('/bank/transactions/' . $id);
        $this->assertSame(403, $b->post('/bank/transactions/' . $id . '/reverse', ['reason' => 'Not allowed', 'reversal_date' => '2026-01-12'])['status']);
        $this->assertSame(403, $b->get('/bank/transfers/create')['status']);
        $b->get('/bank/accounts/' . $this->b);
        $this->assertSame(403, $b->post('/bank/accounts/' . $this->b . '/reveal', [])['status']);
        $this->assertNotContains('50100999988887777', $b->get('/bank/accounts/' . $this->b . '?tab=information')['body'], 'full number never rendered');
    }

    public function testPartnerLoginSeesOnlyMappedAccountsReadOnly(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'alpha.login@example.com');
        $list = $b->get('/bank/accounts');
        $this->assertSame(200, $list['status']);
        $this->assertContains('Alpha operations', $list['body']);
        $this->assertNotContains('Bravo stores', $list['body']);
        $this->assertSame(404, $b->get('/bank/accounts/' . $this->b)['status']);
        $this->assertSame(403, $b->get('/bank')['status'], 'no dashboard permission by default');
        $this->assertSame(403, $b->get('/bank/accounts/create')['status']);
        $b->get('/bank/accounts');
        $this->assertSame(403, $b->post('/bank/accounts/' . $this->a . '/partners', ['partner_id' => 1, 'relationship' => 'other', 'effective_from' => '2025-04-01'])['status']);
    }

    public function testViewerAndManagerPermissions(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'viewer@example.com');
        foreach (['/bank', '/bank/accounts', '/bank/accounts/' . $this->a, '/bank/transactions', '/bank/reports', '/bank/statements'] as $url) {
            $this->assertSame(403, $b->get($url)['status'], $url);
        }
        $m = $this->browser();
        $this->signIn($m, 'manager@example.com');
        $this->assertSame(200, $m->get('/bank')['status']);
        $this->assertContains('Alpha operations', $m->get('/bank/accounts')['body']);
        $this->assertSame(403, $m->get('/bank/accounts/create')['status']);
        $this->assertSame(403, $m->get('/bank/accounts/export?format=csv')['status']);
        $this->assertSame(403, $m->get('/bank/transactions/create?type=deposit')['status']);
    }

    public function testUserSpecificOverridesAndFieldRules(): void
    {
        $acctId = (int) App::db()->scalar("SELECT id FROM users WHERE email = 'acct@example.com'");
        $perms = new PermissionService(App::db());
        $deny = (int) App::db()->scalar("SELECT id FROM permissions WHERE slug = 'bank_accounts.create_deposit'");
        $manage = (int) App::db()->scalar("SELECT id FROM permissions WHERE slug = 'bank_accounts.manage_transactions'");
        $download = (int) App::db()->scalar("SELECT id FROM permissions WHERE slug = 'bank_accounts.download_documents'");
        $perms->setUserOverrides($acctId, [$deny => 'deny', $manage => 'deny', $download => 'deny'], $this->root, null, 'Read-only this month');
        $perms->setUserFieldAccess($acctId, [$this->fieldId('current_balance') => 'masked', $this->fieldId('counterparty_name') => 'hidden', $this->fieldId('internal_notes') => 'hidden'], $this->root);
        $b = $this->browser();
        $this->signIn($b, 'acct@example.com');
        $this->assertSame(403, $b->get('/bank/transactions/create?type=deposit')['status'], 'user-specific deny overrides the role grant');
        $page = $b->get('/bank/accounts/' . $this->b);
        $this->assertNotContains('81,234.56', $page['body'], 'balance masked');
        $this->assertContains('₹ ••••••', $page['body']);
        $info = $b->get('/bank/accounts/' . $this->b . '?tab=information')['body'];
        $this->assertNotContains('Operated by Rohan', $info, 'hidden field not rendered');
        $tx = $b->get('/bank/transactions')['body'];
        $this->assertNotContains('Secret Customer', $tx);
        $csv = $b->get('/bank/reports/transactions/export?format=csv')['body'];
        $this->assertNotContains('Secret Customer', $csv, 'hidden in exports');
        $this->assertNotContains('1234.56', $csv, 'masked amounts in exports');
        $this->assertSame(403, $b->get('/bank/statements/1/download')['status'], 'can view statements but not download');
    }

    public function testAdminFlowsCsrfFullNumberAndExports(): void
    {
        $b = $this->browser();
        $this->signIn($b, 'root@example.com');
        $b->get('/bank/accounts/' . $this->a);
        $this->assertSame(419, $b->post('/bank/accounts/' . $this->a . '/status', ['status' => 'frozen', 'reason' => 'x', '_csrf' => 'wrong'], false)['status']);
        $b->get('/bank/accounts/' . $this->a);
        $reveal = $b->post('/bank/accounts/' . $this->a . '/reveal', []);
        $this->assertSame('50100111122223333', json_decode($reveal['body'], true)['number']);
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'bank.account_number_viewed'"), 'full-number views are logged');
        $b->get('/bank/transfers/create');
        $r = $b->post('/bank/transfers', ['from_account_id' => $this->b, 'to_account_id' => $this->a, 'amount' => '1000', 'transfer_date' => '2026-01-12', 'purpose' => 'Fund Alpha', 'action' => 'submit']);
        $this->assertTrue(in_array($r['status'], [200, 302, 303], true));
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM bank_transactions WHERE transfer_id IS NOT NULL AND status = 'posted'"));
        $b->get('/bank/transfers/create');
        $same = $b->post('/bank/transfers', ['from_account_id' => $this->a, 'to_account_id' => $this->a, 'amount' => '10', 'transfer_date' => '2026-01-12', 'purpose' => 'Same account']);
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transfers'), 'same-account transfer refused');
        foreach (['/bank', '/bank/accounts', '/bank/transactions', '/bank/transfers', '/bank/statements', '/bank/reconciliation', '/bank/reports', '/bank/settings'] as $url) {
            $this->assertSame(200, $b->get($url)['status'], $url);
        }
        foreach (['overview', 'information', 'transactions', 'transfers', 'partners', 'gst', 'activities', 'statements', 'reconciliation', 'documents', 'settings', 'history'] as $tab) {
            $this->assertSame(200, $b->get('/bank/accounts/' . $this->a . '?tab=' . $tab)['status'], $tab);
        }
        foreach (array_keys(\App\Services\Bank\BankReportService::REPORTS) as $report) {
            $this->assertSame(200, $b->get('/bank/reports/' . $report . '?from=2026-01-01&to=2026-12-31')['status'], $report);
            foreach (['csv', 'xlsx', 'pdf'] as $f) {
                $this->assertSame(200, $b->get("/bank/reports/{$report}/export?format={$f}")['status'], "{$report}.{$f}");
            }
        }
        $this->assertSame(200, $b->get('/dashboard')['status']);
        $this->assertSame(200, $b->get('/partners/1?tab=bank')['status']);
    }
}
