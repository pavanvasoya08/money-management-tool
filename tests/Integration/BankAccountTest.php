<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Admin\WorkflowService;
use App\Services\Bank\BankAccountService;
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankReconciliationService;
use App\Services\Bank\BankRelationService;
use App\Services\Bank\BankStatementService;
use App\Services\Bank\BankTransactionService;
use App\Services\Bank\BankTransferService;
use App\Services\Partners\CapitalService;
use App\Services\Partners\PartnerService;
use App\Support\FieldCipher;
use App\Support\Money;
use Tests\TestCase;

/** Phase 5: bank accounts, ledger, transfers, capital links, statements and reconciliation at the service layer. */
final class BankAccountTest extends TestCase
{
    private array $root;
    private BankAccountService $accounts;
    private BankTransactionService $txns;
    private BankTransferService $transfers;
    private BankLedgerService $ledger;
    private int $p1;
    private int $p2;

    public function setUp(): void
    {
        $this->freshDatabase();
        $this->root = $this->makeUser('root@example.com', ['super-admin'], name: 'Root');
        $this->accounts = new BankAccountService(App::db());
        $this->txns = new BankTransactionService(App::db());
        $this->transfers = new BankTransferService(App::db());
        $this->ledger = new BankLedgerService(App::db());
        $ps = new PartnerService(App::db());
        $this->p1 = $ps->create(['name' => 'Alpha Partner', 'date_of_joining' => '2025-04-01'], $this->root);
        $this->p2 = $ps->create(['name' => 'Bravo Partner', 'date_of_joining' => '2025-04-01'], $this->root);
    }

    private function type(string $code): int
    {
        return (int) App::db()->scalar('SELECT id FROM bank_account_types WHERE code = ?', [$code]);
    }

    /** @param array<string, mixed> $extra */
    private function account(string $name, string $number, string $opening = '0', array $extra = []): int
    {
        [$id] = $this->accounts->create($extra + [
            'display_name' => $name, 'bank_name' => 'HDFC Bank', 'branch_name' => 'CG Road', 'account_holder_name' => 'Mehta Traders',
            'account_number' => $number, 'ifsc_code' => 'HDFC0001234', 'account_type_id' => $this->type('current'), 'currency' => 'INR',
            'opening_balance' => $opening, 'opening_balance_date' => '2025-04-01',
        ], $this->root, true);
        return $id;
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function entry(int $account, string $type, string $amount, array $extra = []): array
    {
        return $this->txns->create($extra + ['bank_account_id' => $account, 'type' => $type, 'amount' => $amount, 'transaction_date' => '2026-01-10'], $this->root, true);
    }

    private function balance(int $id): string
    {
        return Money::toDb($this->ledger->balance($id));
    }

    private function enableWorkflow(string $key): void
    {
        $wf = (int) App::db()->scalar('SELECT id FROM approval_workflows WHERE workflow_key = ?', [$key]);
        (new WorkflowService(App::db()))->saveSteps($wf, [['name' => 'Review', 'approver_type' => 'permission', 'min_amount' => '0']], $this->root);
        (new WorkflowService(App::db()))->update($wf, true, null, $this->root);
    }

    public function testCreateMultipleAccountsWithEncryptedNumbersAndDuplicateRules(): void
    {
        $a = $this->account('Central funding', '50100123456789', '100000');
        $b = $this->account('Business A', '50100999888777');
        $acc = $this->accounts->find($a);
        $this->assertSame('active', $acc['status'], 'inactive workflow approves at once');
        $this->assertSame('BNK-0001', $acc['account_code']);
        $this->assertSame('6789', $acc['account_number_last4']);
        $this->assertFalse(str_contains((string) $acc['account_number_encrypted'], '50100123456789'), 'number encrypted at rest');
        $this->assertSame('50100123456789', FieldCipher::decrypt((string) $acc['account_number_encrypted']));
        $this->assertSame('100000.00', $this->balance($a));
        $this->assertSame(2, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_accounts'));
        // Same number + same IFSC = duplicate (spaces ignored).
        $this->assertThrows(ValidationException::class, fn () => $this->account('Again', '5010 0123 4567 89'), 'already recorded');
        // Same number at another bank: allowed only after confirmation.
        $this->assertThrows(ValidationException::class, fn () => $this->account('Other bank', '50100123456789', '0', ['ifsc_code' => 'ICIC0000456', 'bank_name' => 'ICICI Bank']), 'tick the confirmation');
        $this->account('Other bank', '50100123456789', '0', ['ifsc_code' => 'ICIC0000456', 'bank_name' => 'ICICI Bank', 'duplicate_ok' => '1']);
        $this->assertThrows(ValidationException::class, fn () => $this->account('Bad', '123', '0'), '6–20');
        $this->assertThrows(ValidationException::class, fn () => $this->account('Bad', '1234567890', '0', ['ifsc_code' => 'HDFC1234567']), 'IFSC');
        $log = (string) App::db()->scalar("SELECT GROUP_CONCAT(COALESCE(new_values, ''), ' ') FROM activity_logs WHERE action LIKE 'bank.%'");
        $this->assertFalse(str_contains($log, '50100123456789'), 'audit log never holds the full number');
        $this->assertTrue(str_contains($log, 'XXXXXX6789'));
    }

    public function testCentralFundingAccountIsUniqueAndChangedThroughApproval(): void
    {
        $a = $this->account('Central funding', '50100123456789', '100000');
        $b = $this->account('Business A', '50100999888777');
        $this->accounts->requestCentral($this->accounts->find($a), true, 'Joint funding account', $this->root);
        $this->assertSame($a, (int) $this->accounts->central()['id']);
        $this->enableWorkflow('bank_account_update');
        $change = $this->accounts->requestCentral($this->accounts->find($b), true, 'Moving funding to account B', $this->root);
        $this->assertSame('pending', $change['status']);
        $this->assertSame($a, (int) $this->accounts->central()['id'], 'unchanged until approved');
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');
        App::approvals()->approve((int) $change['approval_request_id'], $checker);
        $this->assertSame($b, (int) $this->accounts->central()['id']);
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_accounts WHERE is_central_funding = 1'));
        $this->assertThrows(DomainRuleException::class, fn () => $this->accounts->changeStatus($this->accounts->find($b), 'closed', 'Done', null, $this->root), 'central');
        $viewer = $this->makeUser('acct@example.com', ['accountant'], name: 'Acct');
        $this->assertThrows(AuthorizationException::class, fn () => $this->accounts->requestCentral($this->accounts->find($a), true, 'Try', $viewer));
    }

    public function testPartnerGstAndActivityMappingsAreManyToMany(): void
    {
        $a = $this->account('Central funding', '50100123456789');
        $b = $this->account('Business A', '50100999888777');
        $rel = new BankRelationService(App::db());
        foreach ([[$a, $this->p1, 'joint_holder'], [$a, $this->p2, 'joint_holder'], [$b, $this->p1, 'authorized_operator']] as [$acc, $p, $r]) {
            $rel->addPartner($this->accounts->find($acc), ['partner_id' => $p, 'relationship' => $r, 'effective_from' => '2025-04-01'], $this->root);
        }
        $this->assertSame(2, count($rel->partnerMappings($a)), 'one account, two partners');
        $this->assertSame(2, count($rel->accountsForPartner($this->p1, ['1 = 1', []])), 'one partner, two accounts');
        $this->assertThrows(ValidationException::class, fn () => $rel->addPartner($this->accounts->find($a), ['partner_id' => $this->p1, 'relationship' => 'joint_holder', 'effective_from' => '2025-05-01'], $this->root));
        $m = (int) $rel->partnerMappings($a)[0]['id'];
        $rel->endPartner($this->accounts->find($a), $m, '2026-03-31', 'Left the firm', $this->root);
        $this->assertSame(2, count($rel->partnerMappings($a)), 'ended mapping kept as history');

        $g1 = GstRegistrationTest::g('24AAKFM4821R1Z');
        $g2 = GstRegistrationTest::g('27AAKFM4821R1Z');
        $gs = new \App\Services\Gst\GstRegistrationService(App::db());
        $reg = fn (string $g, string $st): int => $gs->create(['gstin' => $g, 'legal_name' => 'Mehta Traders', 'registration_type_id' => (int) App::db()->scalar("SELECT id FROM gst_registration_types WHERE code = 'regular'"),
            'status' => 'active', 'addr_line1' => '1 Road', 'addr_city' => 'City', 'addr_state_id' => (int) App::db()->scalar('SELECT id FROM gst_states WHERE code = ?', [$st]), 'addr_pin_code' => '380001'], $this->root)[0];
        [$r1, $r2] = [$reg($g1, '24'), $reg($g2, '27')];
        $rel->addGst($this->accounts->find($a), ['gst_registration_id' => $r1, 'designation' => 'primary', 'effective_from' => '2025-04-01'], $this->root);
        $rel->addGst($this->accounts->find($a), ['gst_registration_id' => $r2, 'designation' => 'secondary', 'effective_from' => '2025-04-01'], $this->root);
        $rel->addGst($this->accounts->find($b), ['gst_registration_id' => $r1, 'designation' => 'primary', 'effective_from' => '2025-04-01'], $this->root);
        $this->assertSame(2, count($rel->gstMappings($a)), 'one account, two registrations');
        $this->assertSame(2, count($rel->accountsForRegistration($r1, ['1 = 1', []])), 'one registration, two accounts');
        $this->assertSame(1, (int) App::db()->scalar("SELECT COUNT(*) FROM bank_account_gst_mappings WHERE gst_registration_id = ? AND designation = 'primary' AND status = 'active'", [$r1]), 'one primary per registration');
        $this->assertSame($b, (int) App::db()->scalar("SELECT bank_account_id FROM bank_account_gst_mappings WHERE gst_registration_id = ? AND designation = 'primary'", [$r1]));
        // GST registrations referenced by bank mappings can no longer be archived.
        $this->assertTrue((new \App\Services\Gst\GstLinkRegistry(App::db()))->isReferenced($r1));
        $act = (int) App::db()->scalar("SELECT id FROM gst_business_activities WHERE code = 'marketplace_settlements'");
        $this->assertTrue($act > 0, 'Phase 5 activities added to the Phase 4 master');
        $rel->addActivity($this->accounts->find($b), ['business_activity_id' => $act, 'effective_from' => '2025-04-01'], $this->root);
        $rel->addActivity($this->accounts->find($b), ['business_activity_id' => (int) App::db()->scalar("SELECT id FROM gst_business_activities WHERE code = 'ecommerce'"), 'effective_from' => '2025-04-01'], $this->root);
        $this->assertSame(2, count($rel->activityMappings($b)));
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM partner_capital_transactions'), 'mappings never create capital');
    }

    public function testDepositsWithdrawalsAndBalanceComponents(): void
    {
        $a = $this->account('Business A', '50100999888777', '10000');
        $this->entry($a, 'deposit', '5000', ['counterparty_name' => 'Customer']);
        $this->entry($a, 'withdrawal', '3000.50', ['reference_no' => 'CHQ 1001']);
        $this->entry($a, 'bank_charges', '118', ['transaction_date' => '2026-01-31']);
        $this->entry($a, 'interest', '45.25', ['transaction_date' => '2026-01-31']);
        $this->entry($a, 'adjustment', '100', ['direction' => 'debit', 'description' => 'Correction of a duplicate credit by the bank']);
        $this->assertSame('11826.75', $this->balance($a));
        $b = $this->ledger->breakdown($a);
        $this->assertSame([1000000, 500000, 300050, 11800, 4525, -10000], [$b['opening'], $b['deposits'], $b['withdrawals'], $b['bank_charges'], $b['interest'], $b['adjustments']]);
        $this->assertSame($b['balance'], $this->ledger->balance($a));
        // Draft and rejected entries do not touch the balance.
        $draft = $this->txns->create(['bank_account_id' => $a, 'type' => 'deposit', 'amount' => '999', 'transaction_date' => '2026-01-12'], $this->root);
        $this->assertSame('draft', $draft['status']);
        $this->assertSame('11826.75', $this->balance($a));
        $this->assertThrows(ValidationException::class, fn () => $this->entry($a, 'deposit', '0'), 'greater than zero');
        $this->assertThrows(ValidationException::class, fn () => $this->entry($a, 'deposit', '10', ['transaction_date' => '2025-01-01']), 'opening-balance date');
        // Possible duplicate needs confirmation.
        $this->assertThrows(ValidationException::class, fn () => $this->entry($a, 'withdrawal', '3000.50', ['reference_no' => 'CHQ 1001', 'transaction_date' => '2026-01-11']), 'Possible duplicate');
        $this->entry($a, 'withdrawal', '3000.50', ['reference_no' => 'CHQ 1001', 'transaction_date' => '2026-01-11', 'duplicate_ok' => '1']);
        $ledger = $this->ledger->ledger($a, '2026-01-01', '2026-01-31');
        $this->assertSame(1000000, $ledger['opening']);
        $this->assertSame($this->ledger->balance($a), $ledger['closing']);
        $this->assertSame($ledger['rows'][0]['balance_after'], $ledger['rows'][1]['balance_before'], 'transaction-wise opening and closing balances chain');
    }

    public function testNegativeBalanceIsPreventedUnlessOverdraftIsConfigured(): void
    {
        $a = $this->account('Business A', '50100999888777', '1000');
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry($a, 'withdrawal', '1000.01'), 'Insufficient balance');
        $this->entry($a, 'withdrawal', '1000');
        $this->assertSame('0.00', $this->balance($a));
        $od = $this->account('Overdraft', '50200111222333', '0', ['account_type_id' => $this->type('overdraft'), 'allow_overdraft' => '1', 'overdraft_limit' => '50000']);
        $this->entry($od, 'withdrawal', '20000');
        $this->assertSame('-20000.00', $this->balance($od));
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry($od, 'withdrawal', '30000.01'), 'overdraft limit');
        // Pending withdrawals reserve the balance.
        $this->enableWorkflow('bank_withdrawal');
        $c = $this->account('C', '50300111222333', '5000');
        $this->entry($c, 'withdrawal', '4000');
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry($c, 'withdrawal', '1500'), 'awaiting approval');
    }

    public function testInternalTransferPostsBothSidesAtomicallyAndCannotPostTwice(): void
    {
        $central = $this->account('Central funding', '50100123456789', '500000');
        $a = $this->account('Business A', '50100999888777');
        $b = $this->account('Business B', '50100555444333');
        $this->assertThrows(ValidationException::class, fn () => $this->transfers->create(['from_account_id' => $central, 'to_account_id' => $central, 'amount' => '10', 'transfer_date' => '2026-01-10', 'purpose' => 'Test'], $this->root), 'different account');
        $t1 = $this->transfers->create(['from_account_id' => $central, 'to_account_id' => $a, 'amount' => '200000', 'transfer_date' => '2026-01-10', 'purpose' => 'Working capital', 'reference_no' => 'UTR001'], $this->root, true);
        $this->assertSame('posted', $t1['status']);
        $this->transfers->create(['from_account_id' => $a, 'to_account_id' => $b, 'amount' => '50000', 'transfer_date' => '2026-01-11', 'purpose' => 'Marketplace float'], $this->root, true);
        $this->transfers->create(['from_account_id' => $b, 'to_account_id' => $central, 'amount' => '10000', 'transfer_date' => '2026-01-12', 'purpose' => 'Return surplus'], $this->root, true);
        $this->assertSame(['310000.00', '150000.00', '40000.00'], [$this->balance($central), $this->balance($a), $this->balance($b)]);
        $entries = $this->transfers->entries((int) $t1['id']);
        $this->assertSame(['transfer_out', 'transfer_in'], array_column($entries, 'type'), 'one transfer, two linked entries');
        $this->assertSame([(string) $central, (string) $a], array_map('strval', array_column($entries, 'bank_account_id')));
        // Posting again is impossible (status guard and unique side per transfer).
        $this->assertThrows(DomainRuleException::class, fn () => $this->transfers->post($this->transfers->find((int) $t1['id']), $this->root), 'already posted');
        $this->assertThrows(\PDOException::class, fn () => App::db()->insert('bank_transactions', ['transaction_no' => 'BTX-X', 'bank_account_id' => $a, 'transaction_date' => '2026-01-10', 'type' => 'transfer_in', 'direction' => 'credit',
            'amount' => '1.00', 'transfer_id' => (int) $t1['id'], 'status' => 'posted', 'created_at' => now(), 'updated_at' => now()]));
        // Transfers are not income or expense.
        $m = $this->ledger->movement([$central, $a, $b]);
        $this->assertSame([0, 0, 26000000], [$m['received'], $m['paid'], $m['transfers']]);
        // Insufficient source balance and overdraft rule.
        $this->assertThrows(DomainRuleException::class, fn () => $this->transfers->create(['from_account_id' => $b, 'to_account_id' => $a, 'amount' => '40000.01', 'transfer_date' => '2026-01-13', 'purpose' => 'Too much'], $this->root, true), 'Insufficient');
        // Reversal reverses both sides together.
        $t3 = (int) App::db()->scalar("SELECT id FROM bank_transfers WHERE purpose = 'Return surplus'");
        $this->transfers->reverse($this->transfers->find($t3), 'Sent back by mistake', '2026-01-15', $this->root);
        $this->assertSame(['300000.00', '50000.00'], [$this->balance($central), $this->balance($b)], 'reversal posted on both sides');
        $this->assertSame(4, count($this->transfers->entries($t3)), 'two sides + two reversal rows');
        $this->assertSame('reversed', $this->transfers->find($t3)['status']);
    }

    public function testTransferReversalIsRefusedWhenDestinationNoLongerHoldsTheMoney(): void
    {
        $central = $this->account('Central', '50100123456789', '1000');
        $a = $this->account('A', '50100999888777');
        $t = $this->transfers->create(['from_account_id' => $central, 'to_account_id' => $a, 'amount' => '1000', 'transfer_date' => '2026-01-10', 'purpose' => 'Fund A'], $this->root, true);
        $this->entry($a, 'withdrawal', '600');
        $this->assertThrows(DomainRuleException::class, fn () => $this->transfers->reverse($this->transfers->find((int) $t['id']), 'Wrong account', '2026-01-11', $this->root), 'destination account must still hold');
        $this->assertSame('posted', $this->transfers->find((int) $t['id'])['status'], 'nothing half-reversed');
        $this->assertSame(2, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions WHERE transfer_id = ?', [$t['id']]));
    }

    public function testApprovalWorkflowsGatePostingAndBlockSelfApproval(): void
    {
        $b = $this->account('B', '50100999888777');
        $this->enableWorkflow('bank_account_create');
        [$id, $change] = $this->accounts->create(['display_name' => 'Pending A', 'bank_name' => 'SBI', 'account_holder_name' => 'Mehta Traders', 'account_number' => '30111222333', 'ifsc_code' => 'SBIN0001234',
            'account_type_id' => $this->type('current'), 'opening_balance' => '0', 'opening_balance_date' => '2025-04-01'], $this->root, true);
        $this->assertSame('pending_approval', $this->accounts->find($id)['status']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry($id, 'deposit', '10'), 'cannot take');
        $this->assertThrows(AuthorizationException::class, fn () => App::approvals()->approve((int) $change['approval_request_id'], $this->root), null);
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');
        App::approvals()->approve((int) $change['approval_request_id'], $checker);
        $this->assertSame('active', $this->accounts->find($id)['status']);

        $this->enableWorkflow('bank_deposit');
        $this->enableWorkflow('bank_transfer');
        $dep = $this->entry($id, 'deposit', '7000');
        $this->assertSame('pending_approval', $dep['status']);
        $this->assertSame('0.00', $this->balance($id), 'pending does not count');
        // Approvers need approve_transactions; an accountant cannot approve.
        $acct = $this->makeUser('acct@example.com', ['accountant'], name: 'Acct');
        $this->assertThrows(AuthorizationException::class, fn () => App::approvals()->approve((int) $dep['approval_request_id'], $acct), null);
        App::approvals()->approve((int) $dep['approval_request_id'], $checker);
        $this->assertSame('posted', $this->txns->find((int) $dep['id'])['status'], 'posted on approval');
        $this->assertSame('7000.00', $this->balance($id));
        $rej = $this->entry($id, 'deposit', '500', ['reference_no' => 'R1']);
        App::approvals()->reject((int) $rej['approval_request_id'], $checker, 'Not on the statement');
        $this->assertSame('rejected', $this->txns->find((int) $rej['id'])['status']);
        $this->assertSame('7000.00', $this->balance($id), 'rejected does not count');

        $tr = $this->transfers->create(['from_account_id' => $id, 'to_account_id' => $b, 'amount' => '2000', 'transfer_date' => '2026-01-10', 'purpose' => 'Fund B'], $this->root, true);
        $this->assertSame('pending_approval', $tr['status']);
        $this->assertSame(0, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions WHERE transfer_id = ?', [$tr['id']]), 'no entries before approval');
        App::approvals()->approve((int) $tr['approval_request_id'], $checker);
        $this->assertSame(['5000.00', '2000.00'], [$this->balance($id), $this->balance($b)]);
        $wf = App::db()->first("SELECT approve_action FROM approval_workflows WHERE workflow_key = 'bank_transfer'");
        $this->assertSame('approve_transfers', $wf['approve_action']);
    }

    public function testApprovedEntryThatCannotPostWaitsWithAReason(): void
    {
        $this->enableWorkflow('bank_withdrawal');
        $a = $this->account('A', '50100999888777', '1000');
        $b = $this->account('B', '50100555444333');
        $w = $this->entry($a, 'withdrawal', '800');
        // Meanwhile the balance drops through a transfer recorded without approval workflow.
        $this->transfers->create(['from_account_id' => $a, 'to_account_id' => $b, 'amount' => '200', 'transfer_date' => '2026-01-10', 'purpose' => 'Fund B'], $this->root, true);
        App::db()->execute("UPDATE bank_transactions SET amount = '900.00' WHERE id = ?", [$w['id']]); // simulate a stale check: more than the posted balance allows
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');
        App::approvals()->approve((int) $w['approval_request_id'], $checker);
        $fresh = $this->txns->find((int) $w['id']);
        $this->assertSame('approved', $fresh['status']);
        $this->assertContains('Insufficient', (string) $fresh['posting_error']);
        $this->assertSame('800.00', $this->balance($a));
    }

    public function testReversalAndAuditHistory(): void
    {
        $a = $this->account('A', '50100999888777', '1000');
        $d = $this->entry($a, 'deposit', '5000');
        $this->assertThrows(ValidationException::class, fn () => $this->txns->reverse($this->txns->find((int) $d['id']), 'x', '2026-01-11', $this->root), 'reason');
        $rid = $this->txns->reverse($this->txns->find((int) $d['id']), 'Deposit belonged to another account', '2026-01-11', $this->root);
        $this->assertSame('1000.00', $this->balance($a));
        $this->assertSame('reversed', $this->txns->find((int) $d['id'])['status']);
        $this->assertSame((int) $d['id'], (int) $this->txns->find($rid)['reversal_of_id']);
        $this->assertThrows(DomainRuleException::class, fn () => $this->txns->reverse($this->txns->find((int) $d['id']), 'Again please', '2026-01-11', $this->root), 'already been reversed');
        $this->assertThrows(DomainRuleException::class, fn () => $this->txns->reverse($this->txns->find($rid), 'Undo the undo', '2026-01-11', $this->root), 'cannot itself');
        $this->assertSame(2, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id = ?', [$a]), 'nothing deleted');
        $actions = array_column(App::db()->select("SELECT action FROM activity_logs WHERE subject_type = 'bank_transaction' AND subject_id = ? ORDER BY id", [$d['id']]), 'action');
        foreach (['bank.transaction_created', 'bank.transaction_submitted', 'bank.transaction_approved', 'bank.transaction_posted', 'bank.transaction_reversed'] as $act) {
            $this->assertTrue(in_array($act, $actions, true), $act);
        }
        $row = App::db()->first("SELECT ip_address, old_values, new_values FROM activity_logs WHERE action = 'bank.transaction_reversed'");
        $this->assertContains('reversed', (string) $row['new_values']);
        $this->assertContains('posted', (string) $row['old_values']);
    }

    public function testPartnerContributionLinksToADepositWithoutDuplicatingCapital(): void
    {
        $central = $this->account('Central funding', '50100123456789');
        $capital = new CapitalService(App::db());
        $cid = $capital->createDraft('contribution', ['partner_id' => $this->p1, 'amount' => Money::parse('250000'), 'transaction_date' => '2026-01-05', 'payment_method' => 'bank_transfer', 'payment_reference' => 'UTR777'], $this->root);
        $capital->submit($capital->find($cid), $this->root);
        $capital->post($capital->find($cid), $this->root);
        $before = (int) App::db()->scalar('SELECT COUNT(*) FROM partner_capital_transactions');
        $this->assertThrows(ValidationException::class, fn () => $this->entry($central, 'deposit', '200000', ['capital_transaction_id' => $cid]), 'must equal');
        $dep = $this->entry($central, 'deposit', '250000', ['capital_transaction_id' => $cid, 'reference_no' => 'UTR777']);
        $this->assertSame('posted', $dep['status']);
        $this->assertSame((int) $this->p1, (int) $dep['partner_id'], 'partner taken from the capital entry');
        $this->assertSame('capital_contribution', $dep['source_module']);
        $this->assertSame($central, (int) App::db()->scalar('SELECT bank_account_id FROM partner_capital_transactions WHERE id = ?', [$cid]), 'Phase 3 entry now names the bank account');
        $this->assertSame($before, (int) App::db()->scalar('SELECT COUNT(*) FROM partner_capital_transactions'), 'no capital entry created');
        $this->assertSame(Money::parse('250000'), App::capitalLedger()->balance($this->p1), 'capital unchanged by the bank entry');
        $this->assertThrows(ValidationException::class, fn () => $this->entry($central, 'deposit', '250000', ['capital_transaction_id' => $cid, 'duplicate_ok' => '1']), 'already linked');
        // Capital is not revenue: contributions are excluded from "received" only if... they count as money received into the bank, but never as income.
        $this->assertSame(Money::parse('250000'), $this->ledger->movement([$central])['received']);
        $this->assertSame(0, count((new BankTransactionService(App::db()))->linkableCapital('contribution', ['1 = 1', []])), 'nothing left to link');
        // Withdrawals link only to capital withdrawals.
        $this->assertThrows(ValidationException::class, fn () => $this->entry($central, 'withdrawal', '250000', ['capital_transaction_id' => $cid]), 'capital withdrawal');
    }

    public function testStatementImportPreviewDuplicatesAndReconciliation(): void
    {
        $a = $this->account('A', '50100999888777', '10000');
        $d1 = $this->entry($a, 'deposit', '5000', ['reference_no' => 'NEFT-123', 'transaction_date' => '2026-01-05']);
        $w1 = $this->entry($a, 'withdrawal', '2000', ['reference_no' => 'CHQ-88', 'transaction_date' => '2026-01-08']);
        $this->entry($a, 'bank_charges', '118', ['transaction_date' => '2026-01-31']);
        $csv = "Txn Date,Narration,Ref No,Withdrawal,Deposit,Balance\n05/01/2026,NEFT from customer,NEFT-123,,\"5,000.00\",15000.00\n09/01/2026,Cheque paid,CHQ-88,2000.00,,13000.00\n"
            . "31/01/2026,Charges,,118.00,,12882.00\n31/01/2026,Interest credit,,,45.00,12927.00\n32/01/2026,Bad date,,1.00,,\n";
        $tmp = tempnam(sys_get_temp_dir(), 'st') . '.csv';
        file_put_contents($tmp, $csv);
        $ss = new BankStatementService(App::db());
        $sid = $ss->upload($this->accounts->find($a), ['name' => 'jan.csv', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)], ['period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'closing_balance' => '12927.00'], $this->root, false);
        $this->assertThrows(ValidationException::class, fn () => $ss->upload($this->accounts->find($a), ['name' => 'jan.csv', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp)], ['period_from' => '2026-01-01', 'period_to' => '2026-01-31'], $this->root, false), 'already uploaded');
        $st = $ss->find($sid);
        $map = BankStatementService::guessMap($ss->sample($st, ',')[0]);
        $this->assertSame(['date' => 0, 'description' => 1, 'reference' => 2, 'debit' => 3, 'credit' => 4], array_intersect_key($map, array_flip(['date', 'description', 'reference', 'debit', 'credit'])));
        $before = (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions');
        $imp = $ss->preview($st, ['map' => $map, 'date_format' => 'd/m/Y', 'delimiter' => ',', 'has_header' => '1'], $this->root);
        $import = App::db()->first('SELECT * FROM bank_statement_imports WHERE id = ?', [$imp]);
        $this->assertSame([5, 4, 1, 0], [(int) $import['rows_total'], (int) $import['rows_valid'], (int) $import['rows_invalid'], (int) $import['rows_duplicate']]);
        $ss->import($import, $this->root);
        $this->assertSame($before, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions'), 'import never creates ledger entries');
        // The same lines in another statement are flagged as duplicates.
        $tmp2 = tempnam(sys_get_temp_dir(), 'st') . '.csv';
        file_put_contents($tmp2, $csv . "15/01/2026,Extra line,,,10.00,\n");
        $sid2 = $ss->upload($this->accounts->find($a), ['name' => 'jan-again.csv', 'tmp_name' => $tmp2, 'error' => 0, 'size' => filesize($tmp2)], ['period_from' => '2026-01-01', 'period_to' => '2026-01-31'], $this->root, false);
        $imp2 = $ss->preview($ss->find($sid2), ['map' => $map, 'date_format' => 'd/m/Y', 'delimiter' => ',', 'has_header' => '1'], $this->root);
        $this->assertSame(4, (int) App::db()->scalar('SELECT rows_duplicate FROM bank_statement_imports WHERE id = ?', [$imp2]));
        $ss->discard(App::db()->first('SELECT * FROM bank_statement_imports WHERE id = ?', [$imp2]));

        $rs = new BankReconciliationService(App::db());
        $rid = $rs->start($this->accounts->find($a), ['period_from' => '2026-01-01', 'period_to' => '2026-01-31', 'statement_id' => $sid], $this->root);
        $rec = $rs->find($rid);
        $sugg = $rs->suggestions($rec);
        $this->assertSame(3, count($sugg), 'deposit, cheque (1 day apart) and charges are suggested');
        $this->assertSame((int) $d1['id'], (int) $sugg[0]['txn']['id'], 'reference match ranks first');
        $rs->acceptSuggestions($rec, array_map(static fn (array $s): string => $s['row']['id'] . ':' . $s['txn']['id'], $sugg), $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $rs->match($rec, (int) $sugg[0]['row']['id'], (int) $w1['id'], $this->root), 'already');
        $s = $rs->summary($rec);
        $this->assertSame([4, 3, 1], [$s['rows'], $s['matched'], $s['unmatched_rows']], 'interest line is missing from the ledger');
        $this->assertThrows(DomainRuleException::class, fn () => $rs->complete($rec, null, $this->root), 'still unmatched');
        // Record the missing interest as a draft from the statement line, approve/post it, then match.
        $line = array_values(array_filter($rs->statementRows($rec), static fn (array $r): bool => $r['match_status'] === 'unmatched'))[0];
        $int = $this->txns->create(['bank_account_id' => $a, 'type' => 'interest', 'amount' => '45', 'transaction_date' => '2026-01-31', 'statement_row_id' => $line['id'], 'description' => $line['description']], $this->root, true);
        $this->assertSame('statement', $int['source_module']);
        $rs->match($rec, (int) $line['id'], (int) $int['id'], $this->root);
        $s = $rs->summary($rec);
        $this->assertSame([0, 0], [$s['unmatched_rows'], $s['difference']]);
        $txBefore = App::db()->select('SELECT id, status, amount FROM bank_transactions ORDER BY id');
        $rs->complete($rec, null, $this->root);
        $this->assertSame($txBefore, App::db()->select('SELECT id, status, amount FROM bank_transactions ORDER BY id'), 'reconciliation never alters entries');
        $rec = $rs->find($rid);
        $this->assertSame('completed', $rec['status']);
        $this->assertSame('12927.00', Money::toDb(Money::fromDb($rec['ledger_closing_balance'])));
        $this->assertThrows(DomainRuleException::class, fn () => $rs->unmatch($rec, 1, 'x', $this->root), 'completed');
        // Reopening goes through approval.
        $this->enableWorkflow('bank_reconciliation_reopen');
        $change = $rs->requestReopen($rec, 'Bank corrected a charge', $this->root);
        $this->assertSame('reopen_pending', $rs->find($rid)['status']);
        $checker = $this->makeUser('checker@example.com', ['super-admin'], name: 'Checker');
        App::approvals()->approve((int) $change['approval_request_id'], $checker);
        $this->assertSame('in_progress', $rs->find($rid)['status']);
        $this->assertSame(1, (int) $rs->find($rid)['times_reopened']);
    }

    public function testStatementAmountParsing(): void
    {
        $this->assertSame(123456789, BankStatementService::amount('₹ 12,34,567.89'));
        $this->assertSame(-50000, BankStatementService::amount('(500.00)'));
        $this->assertSame(-50000, BankStatementService::amount('500.00 Dr'));
        $this->assertSame(50000, BankStatementService::amount('500 Cr'));
        $this->assertSame(0, BankStatementService::amount('', true));
        $this->assertSame(null, BankStatementService::amount('abc'));
    }

    public function testClosingNeedsZeroBalanceAndArchivedAccountsAreReadOnly(): void
    {
        $a = $this->account('A', '50100999888777', '100');
        $this->assertThrows(DomainRuleException::class, fn () => $this->accounts->changeStatus($this->accounts->find($a), 'closed', 'No longer used', null, $this->root), 'balance must be zero');
        $this->entry($a, 'withdrawal', '100');
        $this->assertThrows(DomainRuleException::class, fn () => $this->accounts->archive($this->accounts->find($a), 'Old', $this->root), 'Close the account first');
        $this->accounts->changeStatus($this->accounts->find($a), 'frozen', 'Bank froze it', null, $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => $this->entry($a, 'deposit', '10'), 'cannot take');
        $this->accounts->changeStatus($this->accounts->find($a), 'closed', 'No longer used', '2026-02-01', $this->root);
        $this->assertSame('closed', $this->accounts->find($a)['status']);
        $this->accounts->archive($this->accounts->find($a), 'Closed in 2026', $this->root);
        $this->assertThrows(DomainRuleException::class, fn () => (new BankRelationService(App::db()))->addPartner($this->accounts->find($a), ['partner_id' => $this->p1, 'relationship' => 'beneficiary', 'effective_from' => '2025-04-01'], $this->root), 'read-only');
        $this->assertSame(1, (int) App::db()->scalar('SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id = ?', [$a]), 'history kept');
        $statuses = array_column($this->accounts->historyFor($a), 'to_status');
        $this->assertTrue(in_array('closed', $statuses, true) && in_array('frozen', $statuses, true));
    }

    public function testOpeningBalanceIsLockedAfterActivation(): void
    {
        $a = $this->account('A', '50100999888777', '1000');
        $acc = $this->accounts->find($a);
        $data = ['display_name' => 'A renamed', 'bank_name' => 'HDFC Bank', 'branch_name' => 'CG Road', 'account_holder_name' => 'Mehta Traders', 'account_number' => '', 'ifsc_code' => 'HDFC0001234',
            'account_type_id' => $this->type('current'), 'currency' => 'INR', 'opening_balance' => '1000', 'opening_balance_date' => '2025-04-01'];
        $r = $this->accounts->update($acc, $data, $this->root);
        $this->assertSame(['display_name'], $r['direct']);
        $this->assertSame([], $r['key'], 'blank number keeps the stored one');
        $this->assertThrows(ValidationException::class, fn () => $this->accounts->update($this->accounts->find($a), ['opening_balance' => '5000'] + $data, $this->root), 'opening-balance adjustment');
        $this->entry($a, 'opening_adjustment', '500', ['direction' => 'credit', 'description' => 'Opening balance was understated', 'transaction_date' => '2025-04-01']);
        $this->assertSame('1500.00', $this->balance($a));
        $this->assertSame(50000, $this->ledger->breakdown($a)['opening_adjustments']);
        // Key change applies through the (inactive) key-detail workflow.
        $r = $this->accounts->update($this->accounts->find($a), ['account_holder_name' => 'Mehta Traders LLP'] + $data, $this->root, 'Converted to LLP');
        $this->assertSame(['account_holder_name'], $r['key']);
        $this->assertSame('Mehta Traders LLP', $this->accounts->find($a)['account_holder_name']);
    }

    public function testStatementColumnAndDateFormatGuessing(): void
    {
        $m = BankStatementService::guessMap(['Value Date', 'Description', 'Reference', 'Debit', 'Credit', 'Balance']);
        $this->assertSame(0, $m['date'], 'value date used when it is the only date column');
        $this->assertSame(3, $m['debit']);
        $this->assertNull($m['amount'], 'debit/credit wins over a signed amount');
        $this->assertSame(0, BankStatementService::guessMap(['Txn Date', 'Value Date', 'Amount'])['date'], 'transaction date preferred');
        $this->assertSame('d-m-Y', BankStatementService::guessDateFormat([['03-11-2026'], ['18-11-2026']], 0));
        $this->assertSame('d/m/Y', BankStatementService::guessDateFormat([['07/10/2026'], ['21/10/2026']], 0), 'DD/MM preferred for Indian statements');
        $this->assertSame('m/d/Y', BankStatementService::guessDateFormat([['10/21/2026']], 0), 'only MM/DD fits day 21 in the first part');
        $this->assertNull(BankStatementService::guessDateFormat([['not a date']], 0));
    }
}
