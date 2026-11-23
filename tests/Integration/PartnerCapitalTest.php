<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Models\User;
use App\Services\Admin\PermissionService;
use App\Services\Admin\WorkflowService;
use App\Services\Partners\CapitalService;
use App\Services\Partners\PartnerService;
use App\Services\Partners\ProfitSharingService;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Percent;
use Tests\TestCase;

/** Phase 3: partners, capital register, ledger maths, reversals, profit sharing, audit. */
final class PartnerCapitalTest extends TestCase
{
    private array $root;
    private PartnerService $partners;
    private CapitalService $capital;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $this->partners = new PartnerService(App::db());
        $this->capital = new CapitalService(App::db());
    }

    private function partner(string $name, array $extra = []): int
    {
        return $this->partners->create(['name' => $name, 'date_of_joining' => '2025-04-01'] + $extra, $this->root);
    }

    /** Draft → submit → (auto-approved, workflow inactive) → post. Returns id. */
    private function posted(string $type, int $partnerId, string $amount, array $extra = []): int
    {
        $id = $this->capital->createDraft($type, $extra + ['partner_id' => $partnerId, 'amount' => Money::parse($amount), 'transaction_date' => '2026-01-10',
            'payment_method' => 'bank_transfer', 'reason' => 'Test reason'], $this->root);
        $this->capital->submit($this->capital->find($id), $this->root);
        $this->capital->post($this->capital->find($id), $this->root);
        return $id;
    }

    private function balance(int $partnerId): int
    {
        return App::capitalLedger()->balance($partnerId);
    }

    public function testCreateMultiplePartnersWithUniqueIdsAndCapitalAccounts(): void
    {
        $ids = [];
        foreach (['Partner A', 'Partner B', 'Partner C', 'Partner D', 'Partner E', 'Partner F'] as $n) {
            $ids[] = $this->partner($n);
        }
        $codes = array_column(App::db()->select('SELECT partner_code FROM partners ORDER BY id'), 'partner_code');
        $this->assertSame(['PTR-0001', 'PTR-0002', 'PTR-0003', 'PTR-0004', 'PTR-0005', 'PTR-0006'], $codes, 'no fixed partner count');
        $this->assertSame(6, (int) App::db()->scalar('SELECT COUNT(*) FROM partner_capital_accounts'));
        foreach ($ids as $id) {
            $this->assertSame(0, $this->balance($id), 'new partners start at zero');
        }
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM user_roles WHERE user_id <> ?', [$this->root['id']]), 'creating partners grants no permissions');
        $this->assertSame(6, (int) App::db()->scalar("SELECT COUNT(*) FROM activity_logs WHERE action = 'partner.created'"));
    }

    public function testPanValidationAndUniqueness(): void
    {
        $this->partner('Partner A', ['pan_number' => 'abcpe1234f']);
        $this->assertSame('ABCPE1234F', App::db()->scalar('SELECT pan_number FROM partners'));
        $this->assertThrows(ValidationException::class, fn () => $this->partner('Partner B', ['pan_number' => 'ABCPE1234F']));
        $this->assertThrows(ValidationException::class, fn () => $this->partner('Partner C', ['pan_number' => 'ABCDE12345']));
        $this->assertFalse(PartnerService::validatePan('ABCXE1234F'), 'invalid holder type');
    }

    public function testEditPartnerProfileIsAuditedWithMaskedPan(): void
    {
        $id = $this->partner('Partner A', ['city' => 'Pune']);
        $this->partners->update($this->partners->find($id), ['name' => 'Partner A Ltd', 'city' => 'Mumbai', 'pan_number' => 'AAAPA1234Z', 'date_of_joining' => '2025-04-01'], $this->root);
        $p = $this->partners->find($id);
        $this->assertSame('Partner A Ltd', $p['name']);
        $log = App::db()->first("SELECT old_values, new_values FROM activity_logs WHERE action = 'partner.updated'");
        $this->assertContains('Pune', (string) $log['old_values']);
        $this->assertContains('Mumbai', (string) $log['new_values']);
        $this->assertNotContains('AAAPA1234Z', (string) $log['new_values'], 'PAN is masked in the audit log');
        $this->assertContains('XXXXXX234Z', (string) $log['new_values']);
    }

    public function testLinkLoginAccountsOneUserPerPartner(): void
    {
        $a = $this->partner('Partner A');
        $b = $this->partner('Partner B');
        $u = $this->makeUser('pa@example.com');
        $this->partners->link($this->partners->find($a), (int) $u['id'], $this->root);
        $this->assertSame((int) $u['id'], (int) $this->partners->find($a)['user_id']);
        $this->assertThrows(ValidationException::class, fn () => $this->partners->link($this->partners->find($b), (int) $u['id'], $this->root));
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM user_roles WHERE user_id = ?', [$u['id']]), 'linking changes no roles');
        $this->partners->unlink($this->partners->find($a), $this->root);
        $this->assertNull($this->partners->find($a)['user_id']);
        $this->assertSame(['unlinked', 'linked'], array_column($this->partners->linkHistory($a), 'action'));

        // Create a login: limited Partner role only, temporary password must be changed.
        $uid = $this->partners->createLogin($this->partners->find($b), ['email' => 'pb@example.com', 'password' => 'Temp-Passw0rd!9', 'assign_partner_role' => true], $this->root);
        $login = User::find($uid);
        $this->assertSame(1, (int) $login['must_change_password']);
        $this->assertSame(['partner'], array_column(User::roles($uid), 'slug'));
        $this->assertFalse(App::gate()->allows($login, 'users.assign_roles'), 'partners cannot assign roles');
        $this->assertFalse(App::gate()->allows($login, 'super_admin.manage_permissions'));
    }

    public function testDifferentAndRepeatedContributionsAreSeparateEntries(): void
    {
        $a = $this->partner('Partner A');
        $b = $this->partner('Partner B');
        $this->posted('contribution', $a, '500000');
        $this->posted('contribution', $b, '300000');
        $this->posted('contribution', $a, '125000.50');
        $this->assertSame(2, (int) App::db()->scalar("SELECT COUNT(*) FROM partner_capital_transactions WHERE partner_id = ? AND type = 'contribution'", [$a]), 'never overwritten');
        $this->assertSame(Money::parse('625000.50'), $this->balance($a));
        $this->assertSame(Money::parse('300000'), $this->balance($b));
        $this->assertSame('CC-2026-00003', App::db()->scalar('SELECT MAX(transaction_no) FROM partner_capital_transactions'));
    }

    public function testApprovalWorkflowGatesPostingAndRejectionNeverAffectsBalance(): void
    {
        $a = $this->partner('Partner A');
        $wf = (int) App::db()->scalar("SELECT id FROM approval_workflows WHERE workflow_key = 'capital_contribution'");
        (new WorkflowService(App::db()))->saveSteps($wf, [['name' => 'Owner review', 'approver_type' => 'permission', 'min_amount' => '0']], $this->root);
        (new WorkflowService(App::db()))->update($wf, true, null, $this->root);
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');

        $id = $this->capital->createDraft('contribution', ['partner_id' => $a, 'amount' => Money::parse('100000'), 'transaction_date' => '2026-01-10', 'payment_method' => 'cash'], $this->root);
        $txn = $this->capital->submit($this->capital->find($id), $this->root);
        $this->assertSame('pending_approval', $txn['status']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->post($this->capital->find($id), $this->root), 'Only approved');
        $this->assertSame(0, $this->balance($a), 'pending entries do not affect balances');
        App::approvals()->approve((int) $txn['approval_request_id'], $checker);
        $txn = $this->capital->find($id);
        $this->assertSame('approved', $txn['status']);
        $this->assertSame((int) $checker['id'], (int) $txn['approved_by']);
        $this->assertSame(0, $this->balance($a), 'approved but unposted does not count');
        $this->capital->post($txn, $this->root);
        $this->assertSame(Money::parse('100000'), $this->balance($a));

        $id2 = $this->capital->createDraft('contribution', ['partner_id' => $a, 'amount' => Money::parse('5000'), 'transaction_date' => '2026-01-11', 'payment_method' => 'upi'], $this->root);
        $t2 = $this->capital->submit($this->capital->find($id2), $this->root);
        App::approvals()->reject((int) $t2['approval_request_id'], $checker, 'Wrong partner');
        $t2 = $this->capital->find($id2);
        $this->assertSame('rejected', $t2['status']);
        $this->assertSame('Wrong partner', $t2['rejection_reason']);
        $this->assertSame(Money::parse('100000'), $this->balance($a), 'rejected entries never count');
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->post($this->capital->find($id2), $this->root));
        // Rejected entries can be corrected (back to draft) or cancelled.
        $this->capital->updateDraft($t2, ['partner_id' => $a, 'amount' => Money::parse('6000'), 'transaction_date' => '2026-01-11', 'payment_method' => 'upi'], $this->root);
        $this->assertSame('draft', $this->capital->find($id2)['status']);
        $this->capital->cancel($this->capital->find($id2), $this->root);
        $this->assertSame('cancelled', $this->capital->find($id2)['status']);
    }

    public function testWithdrawalsCannotExceedEligibleCapital(): void
    {
        $a = $this->partner('Partner A');
        $this->posted('contribution', $a, '100000');
        $w1 = $this->capital->createDraft('withdrawal', ['partner_id' => $a, 'amount' => Money::parse('60000'), 'transaction_date' => '2026-02-01', 'payment_method' => 'bank_transfer', 'reason' => 'Drawings'], $this->root);
        $this->capital->submit($this->capital->find($w1), $this->root); // approved, not posted: reserves 60,000
        $w2 = $this->capital->createDraft('withdrawal', ['partner_id' => $a, 'amount' => Money::parse('50000'), 'transaction_date' => '2026-02-02', 'payment_method' => 'cash', 'reason' => 'Drawings'], $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->submit($this->capital->find($w2), $this->root), 'Insufficient capital');
        $this->assertSame('draft', $this->capital->find($w2)['status'], 'blocked submission stays a draft');
        $this->assertSame(Money::parse('40000'), App::capitalLedger()->available($a));
        $this->capital->post($this->capital->find($w1), $this->root);
        $this->assertSame(Money::parse('40000'), $this->balance($a));
        $this->assertThrows(ValidationException::class, fn () => $this->capital->createDraft('withdrawal', ['partner_id' => $a, 'amount' => Money::parse('10'), 'transaction_date' => '2026-02-02', 'payment_method' => 'cash'], $this->root));

        // Exception policy needs BOTH the setting and the permission, and is audited.
        App::settings()->update(['allow_capital_overdraw' => '1'], (int) $this->root['id'], ['finance']);
        $this->assertFalse($this->capital->mayOverdraw($this->makeUser('acc@example.com', ['accountant'])));
        $this->capital->submit($this->capital->find($w2), $this->root);
        $this->capital->post($this->capital->find($w2), $this->root);
        $this->assertSame(-Money::parse('10000'), $this->balance($a));
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'capital.overdraw_exception'"));
    }

    public function testRunningBalanceOpeningClosingAndFormula(): void
    {
        $a = $this->partner('Partner A');
        $this->posted('adjustment', $a, '200000', ['adjustment_category' => 'opening_balance', 'direction' => 'credit', 'transaction_date' => '2026-01-01']);
        $this->posted('contribution', $a, '50000', ['transaction_date' => '2026-01-15']);
        $this->posted('withdrawal', $a, '30000', ['transaction_date' => '2026-02-01']);
        $this->posted('adjustment', $a, '1000.25', ['adjustment_category' => 'correction', 'direction' => 'debit', 'transaction_date' => '2026-02-10']);
        $l = App::capitalLedger()->entries($a, '2026-01-10', null);
        $this->assertSame(Money::parse('200000'), $l['opening'], 'opening = balance before the period');
        $this->assertSame([Money::parse('250000'), Money::parse('220000'), Money::parse('218999.75')], array_column($l['rows'], 'running_balance'));
        $this->assertSame(Money::parse('218999.75'), $l['closing']);
        $s = App::capitalLedger()->summary($a);
        $this->assertSame($s['opening'] + $s['contributions'] + $s['adjustments'] - $s['withdrawals'] - $s['reversed'], $s['closing']);
        $this->assertSame($this->balance($a), $s['closing']);
        $this->assertSame(Money::parse('200000'), App::capitalLedger()->balance($a, '2026-01-14'), 'as-of date');
        // A draft or pending entry changes nothing.
        $this->capital->createDraft('contribution', ['partner_id' => $a, 'amount' => Money::parse('999999'), 'transaction_date' => '2026-02-11', 'payment_method' => 'cash'], $this->root);
        $this->assertSame(Money::parse('218999.75'), $this->balance($a));
        $this->assertThrows(ValidationException::class, fn () => $this->capital->createDraft('adjustment', ['partner_id' => $a, 'amount' => 100, 'transaction_date' => '2026-02-11', 'adjustment_category' => 'correction', 'direction' => 'credit'], $this->root));
    }

    public function testReversalRestoresBalanceAndPreventsDuplicatePosting(): void
    {
        $a = $this->partner('Partner A');
        $c = $this->posted('contribution', $a, '80000');
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->post($this->capital->find($c), $this->root), 'already posted');
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM accounting_posting_queue WHERE source_id = ? AND event = 'capital.contribution.posted'", [$c]));
        $q = App::db()->first('SELECT debit_ref, credit_ref FROM accounting_posting_queue WHERE source_id = ?', [$c]);
        $this->assertSame('partner_capital:CAP-PTR-0001', $q['credit_ref'], 'Dr bank/cash, Cr partner capital');
        $this->assertThrows(DomainRuleException::class, fn () => App::postingQueue()->enqueue(CapitalService::SUBJECT, $c, 'capital.contribution.posted', '2026-01-10', 100, 'x', 'y'), 'already been posted');

        $revId = $this->capital->reverse($this->capital->find($c), 'Recorded twice', BusinessDate::today(), $this->root);
        $this->assertSame(0, $this->balance($a));
        $this->assertSame('reversed', $this->capital->find($c)['status']);
        $rev = $this->capital->find($revId);
        $this->assertSame(['reversal', 'debit', 'posted'], [$rev['type'], $rev['direction'], $rev['status']]);
        $this->assertSame($c, (int) $rev['reversal_of_id']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->reverse($this->capital->find($c), 'again', BusinessDate::today(), $this->root), 'already been reversed');
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->reverse($rev, 'again', BusinessDate::today(), $this->root));
        $this->assertSame(2, (int) App::db()->scalar('SELECT COUNT(*) FROM partner_capital_transactions WHERE partner_id = ?', [$a]), 'history kept, nothing deleted');
        $s = App::capitalLedger()->summary($a);
        $this->assertSame([Money::parse('80000'), Money::parse('80000'), 0, 0], [$s['contributions'], $s['reversed'], $s['net_contributed'], $s['closing']]);
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->updateDraft($this->capital->find($c), ['partner_id' => $a, 'amount' => 1, 'transaction_date' => '2026-01-10', 'payment_method' => 'cash'], $this->root), 'Only draft');

        // Reversing a contribution whose capital was already withdrawn is blocked (no negative capital).
        $c2 = $this->posted('contribution', $a, '10000');
        $this->posted('withdrawal', $a, '10000');
        $this->assertThrows(DomainRuleException::class, fn () => $this->capital->reverse($this->capital->find($c2), 'Mistake', BusinessDate::today(), $this->root), 'Insufficient capital');
    }

    public function testInactiveOrArchivedPartnersTakeNoNewEntriesAndArchiveNeedsZeroBalance(): void
    {
        $a = $this->partner('Partner A');
        $this->posted('contribution', $a, '1000');
        $this->assertThrows(DomainRuleException::class, fn () => $this->partners->archive($this->partners->find($a), '', $this->root), 'posted capital balance');
        $this->partners->setStatus($this->partners->find($a), 'inactive', null, $this->root);
        $this->assertThrows(ValidationException::class, fn () => $this->capital->createDraft('contribution', ['partner_id' => $a, 'amount' => 100, 'transaction_date' => '2026-01-10', 'payment_method' => 'cash'], $this->root));
    }

    public function testProfitSharingHistoryTotalsOverlapAndNoRetroactiveChange(): void
    {
        $a = $this->partner('Partner A');
        $b = $this->partner('Partner B');
        $c = $this->partner('Partner C');
        $svc = new ProfitSharingService(App::db());
        $arr = $svc->arrangement($svc->createArrangement(['name' => 'Main deed', 'description' => null, 'allocation_type' => 'complete', 'calculation_method' => 'net_profit'], $this->root));
        $p = static fn (string $v): int => Percent::parse($v);

        $this->assertThrows(ValidationException::class, fn () => $svc->createAllocation($arr, '2025-04-01', null, [$a => $p('50'), $b => $p('30'), $c => $p('19.9999')], null, $this->root));
        $first = $svc->createAllocation($arr, '2025-04-01', null, [$a => $p('30'), $b => $p('25'), $c => $p('45')], null, $this->root);
        $this->assertSame([['arrangement' => 'Main deed', 'units' => $p('30')]], $svc->activeFor($a));

        $this->assertThrows(ValidationException::class, fn () => $svc->createAllocation($arr, '2025-10-01', null, [$a => $p('40'), $b => $p('30'), $c => $p('30')], null, $this->root));
        $future = BusinessDate::addDays(BusinessDate::today(), 30);
        $second = $svc->createAllocation($arr, $future, BusinessDate::addDays($future, 364), [$a => $p('40'), $b => $p('30'), $c => $p('30')], null, $this->root);
        $this->assertSame(BusinessDate::addDays($future, -1), $svc->allocation($first)['effective_to'], 'previous open-ended allocation ends the day before');
        $this->assertSame($p('30'), $svc->activeFor($a)[0]['units'], 'new percentages are not applied today');
        $this->assertSame($p('40'), $svc->activeFor($a, $future)[0]['units']);
        $this->assertSame(2, count($svc->partnerHistory($a)), 'history preserved');

        // Explicitly-ended allocation is never moved: a new one inside its period is an overlap.
        $this->assertThrows(ValidationException::class, fn () => $svc->createAllocation($arr, BusinessDate::addDays($future, 100), null, [$a => $p('100')], null, $this->root));
        // Allocations already in effect cannot be edited; future ones can.
        $this->assertThrows(DomainRuleException::class, fn () => $svc->updateAllocation($svc->allocation($first), '2025-04-01', null, [$a => $p('100')], null, $this->root));
        $svc->updateAllocation($svc->allocation($second), BusinessDate::addDays($future, 1), BusinessDate::addDays($future, 364), [$a => $p('35'), $b => $p('35'), $c => $p('30')], null, $this->root);
        $this->assertSame(BusinessDate::addDays($future, 0), $svc->allocation($first)['effective_to'], 'auto end-date follows an edited future start');
        // Partial arrangements may total less than 100% but never more.
        $partial = $svc->arrangement($svc->createArrangement(['name' => 'Bonus pool', 'description' => null, 'allocation_type' => 'partial', 'calculation_method' => 'distributable_profit'], $this->root));
        $svc->createAllocation($partial, '2025-04-01', null, [$a => $p('10')], null, $this->root);
        $this->assertThrows(ValidationException::class, fn () => $svc->createAllocation($partial, $future, null, [$a => $p('60'), $b => $p('50')], null, $this->root));
        // Percentages are independent of capital: no capital exists at all here.
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM partner_capital_transactions'));
        $this->assertTrue((bool) App::db()->scalar("SELECT 1 FROM activity_logs WHERE action = 'profit_sharing.allocation_created' AND new_values IS NOT NULL"));
    }

    public function testFinancialActionsAreAuditedWithOldAndNewValues(): void
    {
        $a = $this->partner('Partner A');
        $c = $this->posted('contribution', $a, '1234.56');
        $this->capital->reverse($this->capital->find($c), 'Audit test', BusinessDate::today(), $this->root);
        $actions = array_column(App::db()->select("SELECT action FROM activity_logs WHERE subject_type = 'capital_transaction' ORDER BY id"), 'action');
        foreach (['capital.created', 'capital.submitted', 'capital.approved', 'capital.posted', 'capital.reversed', 'capital.reversal_posted'] as $expected) {
            $this->assertTrue(in_array($expected, $actions, true), $expected);
        }
        $posted = App::db()->first("SELECT * FROM activity_logs WHERE action = 'capital.posted'");
        $this->assertContains('approved', (string) $posted['old_values']);
        $this->assertContains('1234.56', (string) $posted['new_values']);
        $this->assertSame('capital_contributions', $posted['module']);
        $this->assertSame((int) $this->root['id'], (int) $posted['user_id']);
    }

    public function testCapitalPermissionsComeFromThePhase2Registry(): void
    {
        $acc = $this->makeUser('acc@example.com', ['accountant']);
        $this->assertTrue(App::gate()->allows($acc, 'capital_contributions.create'));
        $this->assertFalse(App::gate()->allows($acc, 'capital_contributions.post'), 'accountant records drafts but cannot post');
        $this->assertFalse(App::gate()->allows($acc, 'partners.edit'), 'accountant cannot change partner profiles');
        $mgr = $this->makeUser('mgr@example.com', ['manager']);
        $this->assertFalse(App::gate()->allows($mgr, 'profit_sharing.view'), 'manager cannot see profit sharing');
        // Explicit deny on one user beats the role, as for every module.
        (new PermissionService(App::db()))->setUserOverrides((int) $acc['id'], [(int) App::db()->scalar("SELECT id FROM permissions WHERE slug = 'capital_contributions.create'") => 'deny'], $this->root);
        App::flushAuthorization();
        $acc = User::find((int) $acc['id']);
        $a = $this->partner('Partner A');
        $id = (new CapitalService(App::db()))->createDraft('contribution', ['partner_id' => $a, 'amount' => 100, 'transaction_date' => '2026-01-10', 'payment_method' => 'cash'], $this->root);
        $this->assertThrows(AuthorizationException::class, fn () => $this->capital->submit($this->capital->find($id), $acc));
    }
}
