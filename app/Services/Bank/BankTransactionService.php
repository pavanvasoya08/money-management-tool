<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Validator;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Bank transactions (deposits, withdrawals, charges, interest, adjustments):
 * draft → submit (approval engine; inactive workflow = auto-approve) → approved → posted.
 * Posting happens automatically on approval when the balance allows it; otherwise the entry
 * stays "approved" with a posting note and can be posted later. Posted entries are never
 * edited or deleted: they are reversed by a new posted entry.
 *
 * Partner capital stays in Phase 3. A deposit may reference one posted/approved capital
 * contribution and a withdrawal one capital withdrawal — the bank entry records where the
 * money moved; it never creates or duplicates capital, and capital is not revenue.
 */
final class BankTransactionService
{
    public const SUBJECT = 'bank_transaction';
    /** type => [label, fixed direction (null = choose), workflow, specific create permission] */
    public const TYPES = [
        'deposit' => ['Deposit', 'credit', 'bank_deposit', 'create_deposit'],
        'withdrawal' => ['Withdrawal', 'debit', 'bank_withdrawal', 'create_withdrawal'],
        'bank_charges' => ['Bank charges', 'debit', 'bank_withdrawal', 'manage_transactions'],
        'interest' => ['Interest received', 'credit', 'bank_deposit', 'manage_transactions'],
        'adjustment' => ['Other bank adjustment', null, 'bank_adjustment', 'manage_transactions'],
        'opening_adjustment' => ['Opening-balance adjustment', null, 'bank_adjustment', 'manage_transactions'],
    ];
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'posted' => 'Posted', 'reversed' => 'Reversed', 'cancelled' => 'Cancelled'];
    private static bool $listening = false;

    public function __construct(private readonly Database $db)
    {
    }

    public static function registerApprovalListener(): void
    {
        if (self::$listening) {
            return;
        }
        self::$listening = true;
        ApprovalEngine::onDecision(self::SUBJECT, static function (array $request, string $status): void {
            (new self(App::db()))->applyDecision($request, $status);
        });
    }

    /** Whether the user may record entries of this type. @param array<string, mixed> $user */
    public static function mayCreate(array $user, string $type): bool
    {
        $gate = App::gate();
        return isset(self::TYPES[$type]) && ($gate->allows($user, 'bank_accounts.manage_transactions') || $gate->allows($user, 'bank_accounts.' . self::TYPES[$type][3]));
    }

    // ---------------------------------------------------------------- reading

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT t.*, a.display_name AS account_name, a.account_code, a.account_number_last4, a.currency, p.name AS partner_name, p.partner_code,
                    g.gstin, act.name AS activity_name, tr.transfer_no, o.transaction_no AS reverses_no, rv.id AS reversed_by_id, rv.transaction_no AS reversed_by_no,
                    c.transaction_no AS capital_no, c.type AS capital_type, cu.name AS created_by_name, au.name AS approved_by_name, pu.name AS posted_by_name
             FROM bank_transactions t JOIN bank_accounts a ON a.id = t.bank_account_id
             LEFT JOIN partners p ON p.id = t.partner_id LEFT JOIN gst_registrations g ON g.id = t.gst_registration_id
             LEFT JOIN gst_business_activities act ON act.id = t.business_activity_id LEFT JOIN bank_transfers tr ON tr.id = t.transfer_id
             LEFT JOIN bank_transactions o ON o.id = t.reversal_of_id LEFT JOIN bank_transactions rv ON rv.reversal_of_id = t.id
             LEFT JOIN partner_capital_transactions c ON c.id = t.capital_transaction_id
             LEFT JOIN users cu ON cu.id = t.created_by LEFT JOIN users au ON au.id = t.approved_by LEFT JOIN users pu ON pu.id = t.posted_by
             WHERE t.id = ?',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $f account, type, status, direction, q, from, to, partner, gst, activity
     * @param array{0: string, 1: list<mixed>} $scope constraint on accounts alias a
     * @return array{0: string, 1: list<mixed>}
     */
    public function filterSql(array $f, array $scope): array
    {
        [$where, $params] = $scope;
        $sql = [$where];
        foreach (['account' => 't.bank_account_id = ?', 'partner' => 't.partner_id = ?', 'gst' => 't.gst_registration_id = ?', 'activity' => 't.business_activity_id = ?'] as $k => $cond) {
            if ((int) ($f[$k] ?? 0) > 0) {
                $sql[] = $cond;
                $params[] = (int) $f[$k];
            }
        }
        $type = (string) ($f['type'] ?? '');
        if (isset(BankLedgerService::TYPE_LABELS[$type])) {
            $sql[] = 't.type = ?';
            $params[] = $type;
        } elseif ($type === 'non_transfer') {
            $sql[] = "t.type NOT IN ('transfer_in', 'transfer_out') AND t.transfer_id IS NULL";
        }
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $sql[] = 't.status = ?';
            $params[] = $status;
        } elseif ($status === 'open') {
            $sql[] = "t.status IN ('draft', 'pending_approval', 'approved')";
        }
        if (in_array($f['direction'] ?? '', ['credit', 'debit'], true)) {
            $sql[] = 't.direction = ?';
            $params[] = $f['direction'];
        }
        foreach (['from' => 't.transaction_date >= ?', 'to' => 't.transaction_date <= ?'] as $k => $cond) {
            if (BusinessDate::isValid((string) ($f[$k] ?? ''))) {
                $sql[] = $cond;
                $params[] = $f[$k];
            }
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $sql[] = '(LOWER(t.transaction_no) LIKE ? OR LOWER(COALESCE(t.reference_no, \'\')) LIKE ? OR LOWER(COALESCE(t.utr_cheque_no, \'\')) LIKE ? OR LOWER(COALESCE(t.description, \'\')) LIKE ? OR LOWER(COALESCE(t.counterparty_name, \'\')) LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $sql), $params];
    }

    /** @param array<string, mixed> $f @param array{0: string, 1: list<mixed>} $scope */
    public function paginate(array $f, int $page, int $perPage, array $scope): Paginator
    {
        [$w, $p] = $this->filterSql($f, $scope);
        $base = 'FROM bank_transactions t JOIN bank_accounts a ON a.id = t.bank_account_id LEFT JOIN partners pa ON pa.id = t.partner_id';
        $total = (int) $this->db->scalar("SELECT COUNT(*) {$base} WHERE {$w}", $p);
        $rows = $this->db->select("SELECT t.*, a.display_name AS account_name, a.account_code, a.currency, pa.name AS partner_name {$base} WHERE {$w}
            ORDER BY t.transaction_date DESC, t.id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $p);
        return new Paginator($rows, $total, $page, $perPage);
    }

    /** Capital entries that a new bank entry may reference. @param array{0: string, 1: list<mixed>} $partnerScope on alias p @return list<array<string, mixed>> */
    public function linkableCapital(string $capitalType, array $partnerScope, ?int $accountId = null): array
    {
        [$w, $p] = $partnerScope;
        return $this->db->select(
            "SELECT c.id, c.transaction_no, c.amount, c.transaction_date, c.status, c.payment_reference, p.name AS partner_name, p.id AS partner_id
             FROM partner_capital_transactions c JOIN partners p ON p.id = c.partner_id
             WHERE {$w} AND c.type = ? AND c.status IN ('approved', 'posted') AND (c.bank_account_id IS NULL" . ($accountId ? ' OR c.bank_account_id = ' . (int) $accountId : '') . ")
               AND NOT EXISTS (SELECT 1 FROM bank_transactions b WHERE b.capital_transaction_id = c.id AND b.status NOT IN ('rejected', 'cancelled', 'reversed'))
             ORDER BY c.transaction_date DESC LIMIT 200",
            array_merge($p, [$capitalType])
        );
    }

    /** Bank entries that reference a capital entry (for the Phase 3 entry page). @return list<array<string, mixed>> */
    public function forCapital(int $capitalId): array
    {
        return $this->db->select('SELECT t.id, t.transaction_no, t.status, t.transaction_date, t.amount, a.display_name AS account_name, a.account_code, a.id AS account_id
            FROM bank_transactions t JOIN bank_accounts a ON a.id = t.bank_account_id WHERE t.capital_transaction_id = ? ORDER BY t.id DESC', [$capitalId]);
    }

    // ---------------------------------------------------------------- create / submit / cancel

    /**
     * @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array<string, mixed> the stored entry (after submit when $submit)
     */
    public function create(array $data, array $actor, bool $submit = false): array
    {
        $type = (string) ($data['type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw ValidationException::withMessage('type', 'Choose a transaction type.');
        }
        if (!self::mayCreate($actor, $type)) {
            throw new AuthorizationException('bank_accounts.' . self::TYPES[$type][3], 'You may not record this type of entry.');
        }
        $acc = $this->account((int) ($data['bank_account_id'] ?? 0));
        BankAccountService::assertUsable($acc, 'new entries');
        $clean = $this->validate($data, $acc, $type);
        $this->duplicateCheck($clean, (int) $acc['id'], null, !empty($data['duplicate_ok']));
        $id = $this->db->transaction(function (Database $db) use ($clean, $acc, $type, $actor): int {
            if ($clean['capital_transaction_id'] !== null) {
                $this->assertCapitalFree((int) $clean['capital_transaction_id'], null);
            }
            return $db->insert('bank_transactions', $clean + [
                'transaction_no' => $this->nextNo(), 'bank_account_id' => (int) $acc['id'], 'type' => $type, 'status' => 'draft',
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $txn = $this->find($id);
        App::activity()->log('bank.transaction_created', "Recorded {$txn['transaction_no']}: " . self::TYPES[$type][0] . ' of ' . Money::format(Money::fromDb($txn['amount'])) . " on {$acc['display_name']}",
            'bank_transaction', $id, module: 'bank_accounts', newValues: self::auditView($txn));
        return $submit ? $this->submit($txn, $actor) : $txn;
    }

    /** @param array<string, mixed> $txn @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function updateDraft(array $txn, array $data, array $actor): array
    {
        if ($txn['status'] !== 'draft' && $txn['status'] !== 'rejected') {
            throw new DomainRuleException('Only drafts and rejected entries can be edited. Posted entries are corrected by reversal.');
        }
        $acc = $this->account((int) $txn['bank_account_id']);
        BankAccountService::assertUsable($acc);
        $clean = $this->validate($data + ['type' => $txn['type']], $acc, (string) $txn['type']);
        $this->duplicateCheck($clean, (int) $acc['id'], (int) $txn['id'], !empty($data['duplicate_ok']));
        $this->db->transaction(function () use ($clean, $txn, $actor): void {
            if ($clean['capital_transaction_id'] !== null) {
                $this->assertCapitalFree((int) $clean['capital_transaction_id'], (int) $txn['id']);
            }
            $changed = $this->db->execute('UPDATE bank_transactions SET ' . implode(', ', array_map(static fn (string $k): string => "{$k} = ?", array_keys($clean)))
                . ", status = 'draft', rejection_reason = NULL, updated_by = ?, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status IN ('draft', 'rejected')",
                array_merge(array_values($clean), [(int) $actor['id'], now(), (int) $txn['id']]));
            if ($changed !== 1) {
                throw new DomainRuleException('This entry was changed by someone else. Reload and try again.');
            }
        });
        $new = $this->find((int) $txn['id']);
        App::activity()->log('bank.transaction_updated', "Edited draft {$txn['transaction_no']}", 'bank_transaction', (int) $txn['id'], module: 'bank_accounts', oldValues: self::auditView($txn), newValues: self::auditView($new));
        return $new;
    }

    /** @param array<string, mixed> $txn @param array<string, mixed> $actor */
    public function submit(array $txn, array $actor): array
    {
        if ($txn['status'] !== 'draft') {
            throw new DomainRuleException('Only drafts can be submitted.');
        }
        if ($txn['transfer_id'] !== null || $txn['type'] === 'reversal') {
            throw new DomainRuleException('Transfer and reversal entries are created by their own process.');
        }
        $acc = $this->account((int) $txn['bank_account_id']);
        BankAccountService::assertUsable($acc);
        $amount = Money::fromDb($txn['amount']);
        if ($txn['direction'] === 'debit') {
            $this->assertCapacity($acc, $amount, (int) $txn['id'], false);
        }
        [, , $workflow, $perm] = self::TYPES[$txn['type']];
        $permission = App::gate()->allows($actor, 'bank_accounts.' . $perm) ? 'bank_accounts.' . $perm : 'bank_accounts.manage_transactions';
        $this->transition($txn, ['draft'], ['status' => 'pending_approval', 'submitted_at' => now(), 'posting_error' => null, 'updated_by' => (int) $actor['id']]);
        try {
            $request = App::approvals()->submit($workflow, self::SUBJECT, (int) $txn['id'], Money::toFloat($amount),
                self::TYPES[$txn['type']][0] . " {$txn['transaction_no']} — {$acc['display_name']} — " . Money::format($amount), $actor, $permission);
        } catch (\Throwable $e) {
            $this->db->execute("UPDATE bank_transactions SET status = 'draft', submitted_at = NULL WHERE id = ? AND status = 'pending_approval'", [$txn['id']]);
            throw $e;
        }
        $this->db->execute('UPDATE bank_transactions SET approval_request_id = ? WHERE id = ?', [$request['id'], $txn['id']]);
        App::activity()->log('bank.transaction_submitted', "Submitted {$txn['transaction_no']} for approval", 'bank_transaction', (int) $txn['id'], module: 'bank_accounts',
            oldValues: ['status' => 'draft'], newValues: ['status' => 'pending_approval', 'approval_request' => (int) $request['id']]);
        return $this->find((int) $txn['id']) ?? $txn;
    }

    /** @param array<string, mixed> $txn @param array<string, mixed> $actor */
    public function cancel(array $txn, array $actor, ?string $reason = null): void
    {
        if (!in_array($txn['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only drafts and rejected entries can be cancelled. Withdraw a pending request from the Approvals page.');
        }
        $this->transition($txn, ['draft', 'rejected'], ['status' => 'cancelled', 'updated_by' => (int) $actor['id']]);
        App::activity()->log('bank.transaction_cancelled', "Cancelled {$txn['transaction_no']}", 'bank_transaction', (int) $txn['id'], module: 'bank_accounts',
            oldValues: ['status' => $txn['status']], newValues: ['status' => 'cancelled', 'reason' => $reason]);
    }

    /** Approval engine callback: approved entries are posted at once when possible. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $txn = $this->find((int) $request['subject_id']);
        if ($txn === null || $txn['status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = $last !== null ? (int) $last['acted_by'] : (int) $request['requested_by'];
        $updates = match ($status) {
            'approved' => ['status' => 'approved', 'approved_by' => $by, 'approved_at' => now()],
            'rejected' => ['status' => 'rejected', 'rejection_reason' => $last['comment'] ?? null],
            'cancelled' => ['status' => 'draft', 'submitted_at' => null, 'approval_request_id' => null],
            default => null,
        };
        if ($updates === null) {
            return;
        }
        $this->transition($txn, ['pending_approval'], $updates);
        App::activity()->log('bank.transaction_' . ($status === 'cancelled' ? 'withdrawn' : $status), ucfirst($status === 'cancelled' ? 'withdrawn' : $status) . " {$txn['transaction_no']}", 'bank_transaction', (int) $txn['id'],
            module: 'bank_accounts', oldValues: ['status' => 'pending_approval'], newValues: ['status' => $updates['status']] + ($status === 'rejected' ? ['reason' => $updates['rejection_reason']] : []), userId: $by);
        if ($status === 'approved') {
            $approved = $this->find((int) $txn['id']);
            try {
                $this->post($approved, ['id' => $by], true);
            } catch (DomainRuleException $e) {
                $this->db->update('bank_transactions', ['posting_error' => mb_substr($e->getMessage(), 0, 255)], ['id' => $txn['id']]);
            }
        }
    }

    // ---------------------------------------------------------------- posting / reversal

    /**
     * Post an approved entry. Locks the account, re-checks the posted balance for debits and
     * moves approved → posted with a guarded UPDATE, so an entry can never be posted twice.
     * @param array<string, mixed> $txn @param array<string, mixed> $actor
     */
    public function post(array $txn, array $actor, bool $automatic = false): void
    {
        if (in_array($txn['status'], ['posted', 'reversed'], true)) {
            throw new DomainRuleException("{$txn['transaction_no']} is already posted.");
        }
        if ($txn['status'] !== 'approved') {
            throw new DomainRuleException('Only approved entries can be posted.');
        }
        $amount = Money::fromDb($txn['amount']);
        $ledger = new BankLedgerService($this->db);
        $this->db->transaction(function (Database $db) use ($txn, $actor, $amount, $ledger): void {
            $ledger->lock([(int) $txn['bank_account_id']]);
            $acc = $this->account((int) $txn['bank_account_id']);
            BankAccountService::assertUsable($acc, 'postings');
            if ($txn['direction'] === 'debit') {
                $this->assertCapacity($acc, $amount, (int) $txn['id'], true);
            }
            $changed = $db->execute("UPDATE bank_transactions SET status = 'posted', posted_by = ?, posted_at = ?, posting_error = NULL, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status = 'approved'",
                [(int) $actor['id'], now(), now(), (int) $txn['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException("{$txn['transaction_no']} was already posted or changed by someone else.");
            }
            if ($txn['capital_transaction_id'] !== null) {
                // Record on the Phase 3 entry which bank account received/paid it (never changes capital amounts).
                $db->execute('UPDATE partner_capital_transactions SET bank_account_id = ? WHERE id = ? AND bank_account_id IS NULL', [(int) $txn['bank_account_id'], (int) $txn['capital_transaction_id']]);
            }
        });
        App::activity()->log('bank.transaction_posted', "Posted {$txn['transaction_no']} (" . Money::format($amount) . ") to {$txn['account_name']}" . ($automatic ? ' on approval' : ''), 'bank_transaction', (int) $txn['id'],
            module: 'bank_accounts', oldValues: ['status' => 'approved'], newValues: ['status' => 'posted', 'amount' => Money::toDb($amount), 'direction' => $txn['direction'], 'balance_after' => Money::toDb($ledger->balance((int) $txn['bank_account_id']))], userId: (int) $actor['id']);
    }

    /**
     * Reverse a posted entry with a new posted entry in the opposite direction. The original
     * is marked reversed; both stay in the ledger. Transfer sides are reversed through the transfer.
     * @param array<string, mixed> $txn @param array<string, mixed> $actor
     */
    public function reverse(array $txn, string $reason, string $date, array $actor): int
    {
        if ($txn['type'] === 'reversal') {
            throw new DomainRuleException('A reversal cannot itself be reversed. Record a new entry instead.');
        }
        if ($txn['transfer_id'] !== null) {
            throw new DomainRuleException('This entry belongs to transfer ' . ($txn['transfer_no'] ?? '') . '. Reverse the transfer, so both sides are reversed together.');
        }
        if ($txn['status'] === 'reversed') {
            throw new DomainRuleException("{$txn['transaction_no']} has already been reversed.");
        }
        if ($txn['status'] !== 'posted') {
            throw new DomainRuleException('Only posted entries can be reversed. Drafts and rejected entries can simply be cancelled.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessage('reason', 'Give a reversal reason (at least 5 characters).');
        }
        if (!BusinessDate::isValid($date) || $date > BusinessDate::today() || $date < $txn['transaction_date']) {
            throw ValidationException::withMessage('reversal_date', 'The reversal date must be between the original date and today.');
        }
        $amount = Money::fromDb($txn['amount']);
        $ledger = new BankLedgerService($this->db);
        $id = $this->db->transaction(function (Database $db) use ($txn, $reason, $date, $actor, $amount, $ledger): int {
            $ledger->lock([(int) $txn['bank_account_id']]);
            $acc = $this->account((int) $txn['bank_account_id']);
            if ($acc['status'] === 'closed') {
                throw new DomainRuleException('Entries on a closed account cannot be reversed.');
            }
            if ($txn['direction'] === 'credit') {
                $this->assertCapacity($acc, $amount, null, true, 'reverse this credit');
            }
            $changed = $db->execute("UPDATE bank_transactions SET status = 'reversed', reversal_reason = ?, updated_by = ?, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status = 'posted'",
                [mb_substr($reason, 0, 500), (int) $actor['id'], now(), (int) $txn['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException("{$txn['transaction_no']} was already reversed or changed by someone else.");
            }
            return $db->insert('bank_transactions', [
                'transaction_no' => $this->nextNo(), 'bank_account_id' => (int) $txn['bank_account_id'], 'transaction_date' => $date, 'type' => 'reversal',
                'direction' => $txn['direction'] === 'credit' ? 'debit' : 'credit', 'amount' => Money::toDb($amount), 'reference_no' => $txn['transaction_no'],
                'description' => 'Reversal of ' . $txn['transaction_no'] . ': ' . mb_substr($reason, 0, 400), 'counterparty_name' => $txn['counterparty_name'],
                'partner_id' => $txn['partner_id'], 'gst_registration_id' => $txn['gst_registration_id'], 'business_activity_id' => $txn['business_activity_id'],
                'source_module' => 'reversal', 'reversal_of_id' => (int) $txn['id'], 'reversal_reason' => mb_substr($reason, 0, 500), 'status' => 'posted',
                'approved_by' => (int) $actor['id'], 'approved_at' => now(), 'posted_by' => (int) $actor['id'], 'posted_at' => now(),
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        App::activity()->log('bank.transaction_reversed', "Reversed {$txn['transaction_no']} (" . Money::format($amount) . ')', 'bank_transaction', (int) $txn['id'],
            module: 'bank_accounts', oldValues: ['status' => 'posted'], newValues: ['status' => 'reversed', 'reversal_id' => $id, 'reason' => $reason, 'date' => $date]);
        return $id;
    }

    /**
     * Link an existing posted deposit/withdrawal to a Phase 3 capital entry (e.g. the deposit
     * was recorded from the bank statement before the contribution). Changes no amounts.
     * @param array<string, mixed> $txn @param array<string, mixed> $actor
     */
    public function linkCapital(array $txn, int $capitalId, array $actor): void
    {
        if (!in_array($txn['type'], ['deposit', 'withdrawal'], true) || in_array($txn['status'], ['rejected', 'cancelled', 'reversed'], true)) {
            throw new DomainRuleException('Only live deposits and withdrawals can be linked to a capital entry.');
        }
        if ($txn['capital_transaction_id'] !== null) {
            throw new DomainRuleException('This entry is already linked to ' . ($txn['capital_no'] ?? 'a capital entry') . '.');
        }
        $this->db->transaction(function (Database $db) use ($txn, $capitalId): void {
            $cap = $this->checkCapital($capitalId, (string) $txn['type'], Money::fromDb($txn['amount']), (int) $txn['bank_account_id']);
            $this->assertCapitalFree($capitalId, (int) $txn['id']);
            $db->update('bank_transactions', ['capital_transaction_id' => $capitalId, 'partner_id' => (int) $cap['partner_id'], 'source_module' => $txn['type'] === 'deposit' ? 'capital_contribution' : 'capital_withdrawal', 'updated_at' => now()], ['id' => $txn['id']]);
            if (in_array($txn['status'], ['posted'], true)) {
                $db->execute('UPDATE partner_capital_transactions SET bank_account_id = ? WHERE id = ? AND bank_account_id IS NULL', [(int) $txn['bank_account_id'], $capitalId]);
            }
        });
        App::activity()->log('bank.capital_linked', "Linked {$txn['transaction_no']} to capital entry #{$capitalId}", 'bank_transaction', (int) $txn['id'], module: 'bank_accounts', newValues: ['capital_transaction_id' => $capitalId]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $acc
     * @param bool $postedOnly true at posting time (balance after posted entries only)
     */
    public function assertCapacity(array $acc, int $amount, ?int $exceptTxn, bool $postedOnly, string $what = 'take this amount out'): void
    {
        $available = (new BankLedgerService($this->db))->available((int) $acc['id'], $exceptTxn, null, $postedOnly);
        if ($amount > $available) {
            throw new DomainRuleException('Insufficient balance to ' . $what . ': ' . Money::format($amount) . ' exceeds the available ' . Money::format(max(0, $available))
                . ' on ' . $acc['display_name'] . ((int) $acc['allow_overdraft'] === 1 ? ' (including the overdraft limit)' : '') . ($postedOnly ? '.' : ' (posted balance minus withdrawals and transfers already awaiting approval).'));
        }
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $acc @return array<string, mixed> */
    private function validate(array $data, array $acc, string $type): array
    {
        foreach (['reference_no', 'utr_cheque_no', 'description', 'counterparty_name', 'amount', 'transaction_date'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? ''));
        }
        $rules = [
            'transaction_date' => 'required|date|before_or_equal:today', 'amount' => 'required|money',
            'reference_no' => 'nullable|string|max:100', 'utr_cheque_no' => 'nullable|string|max:60', 'counterparty_name' => 'nullable|string|max:200',
            'description' => (in_array($type, ['adjustment', 'opening_adjustment'], true) ? 'required|min:5' : 'nullable') . '|string|max:500',
            'partner_id' => 'nullable|integer|exists:partners,id', 'gst_registration_id' => 'nullable|integer|exists:gst_registrations,id',
            'business_activity_id' => 'nullable|integer|exists:gst_business_activities,id', 'capital_transaction_id' => 'nullable|integer|exists:partner_capital_transactions,id',
        ];
        $v = Validator::make($data, $rules, ['transaction_date' => 'Transaction date', 'gst_registration_id' => 'GST registration', 'business_activity_id' => 'Business activity', 'capital_transaction_id' => 'Capital entry']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $c = $v->validated();
        $amount = Money::parse((string) $c['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessage('amount', 'The amount must be greater than zero.');
        }
        if ($c['transaction_date'] < $acc['opening_balance_date']) {
            throw ValidationException::withMessage('transaction_date', 'The date cannot be before the opening-balance date (' . format_date($acc['opening_balance_date']) . '). Use an opening-balance adjustment instead.');
        }
        $direction = self::TYPES[$type][1] ?? (in_array($data['direction'] ?? '', ['credit', 'debit'], true) ? $data['direction'] : null);
        if ($direction === null) {
            throw ValidationException::withMessage('direction', 'Choose whether the adjustment increases (credit) or reduces (debit) the balance.');
        }
        $capitalId = (int) ($c['capital_transaction_id'] ?? 0) ?: null;
        $partnerId = (int) ($c['partner_id'] ?? 0) ?: null;
        $source = 'manual';
        if ($capitalId !== null) {
            $cap = $this->checkCapital($capitalId, $type, $amount, (int) $acc['id']);
            $partnerId = (int) $cap['partner_id'];
            $source = $type === 'deposit' ? 'capital_contribution' : 'capital_withdrawal';
        }
        $rowId = (int) ($data['statement_row_id'] ?? 0) ?: null;
        if ($rowId !== null && !$this->db->scalar('SELECT 1 FROM bank_statement_rows WHERE id = ? AND bank_account_id = ?', [$rowId, $acc['id']])) {
            $rowId = null;
        }
        return [
            'transaction_date' => $c['transaction_date'], 'direction' => $direction, 'amount' => Money::toDb($amount),
            'reference_no' => $c['reference_no'] ?: null, 'utr_cheque_no' => $c['utr_cheque_no'] ?: null, 'description' => $c['description'] ?: null,
            'counterparty_name' => $c['counterparty_name'] ?: null, 'partner_id' => $partnerId,
            'gst_registration_id' => (int) ($c['gst_registration_id'] ?? 0) ?: null, 'business_activity_id' => (int) ($c['business_activity_id'] ?? 0) ?: null,
            'capital_transaction_id' => $capitalId, 'source_module' => $rowId !== null && $source === 'manual' ? 'statement' : $source, 'statement_row_id' => $rowId,
        ];
    }

    /** @return array<string, mixed> */
    private function checkCapital(int $capitalId, string $type, int $amount, int $accountId): array
    {
        $cap = $this->db->first('SELECT c.*, p.name AS partner_name FROM partner_capital_transactions c JOIN partners p ON p.id = c.partner_id WHERE c.id = ?', [$capitalId]);
        $expected = ['deposit' => 'contribution', 'withdrawal' => 'withdrawal'][$type] ?? null;
        if ($cap === null || $expected === null || $cap['type'] !== $expected) {
            throw ValidationException::withMessage('capital_transaction_id', $expected === null ? 'Only deposits and withdrawals can reference a capital entry.' : 'Choose a capital ' . $expected . '.');
        }
        if (!in_array($cap['status'], ['approved', 'posted'], true)) {
            throw ValidationException::withMessage('capital_transaction_id', "{$cap['transaction_no']} is " . str_replace('_', ' ', $cap['status']) . '; only approved or posted capital entries can be linked.');
        }
        if (Money::fromDb($cap['amount']) !== $amount) {
            throw ValidationException::withMessage('amount', 'The amount must equal the capital entry ' . $cap['transaction_no'] . ' (' . Money::format(Money::fromDb($cap['amount'])) . '). Partner capital is recorded once, in Phase 3.');
        }
        if ($cap['bank_account_id'] !== null && (int) $cap['bank_account_id'] !== $accountId) {
            throw ValidationException::withMessage('capital_transaction_id', "{$cap['transaction_no']} is already recorded against another bank account.");
        }
        return $cap;
    }

    /** One live bank entry per capital entry (checked inside the caller's transaction). */
    private function assertCapitalFree(int $capitalId, ?int $exceptTxn): void
    {
        $this->db->execute('UPDATE partner_capital_transactions SET lock_version = lock_version + 1 WHERE id = ?', [$capitalId]);
        $other = $this->db->scalar("SELECT transaction_no FROM bank_transactions WHERE capital_transaction_id = ? AND status NOT IN ('rejected', 'cancelled', 'reversed')" . ($exceptTxn ? ' AND id <> ' . $exceptTxn : ''), [$capitalId]);
        if ($other) {
            throw ValidationException::withMessage('capital_transaction_id', "This capital entry is already linked to bank entry {$other}. A contribution is recorded once.");
        }
    }

    /** Possible duplicate: same account, direction and amount within the window, with the same reference or counterparty. @param array<string, mixed> $c */
    private function duplicateCheck(array $c, int $accountId, ?int $exceptId, bool $confirmed): void
    {
        if ($confirmed) {
            return;
        }
        $days = (int) App::settings()->get('bank_duplicate_window_days', 3);
        $params = [$accountId, $c['direction'], $c['amount'], BusinessDate::addDays($c['transaction_date'], -$days), BusinessDate::addDays($c['transaction_date'], $days)];
        $match = [];
        if ($c['reference_no'] !== null) {
            $match[] = 'LOWER(reference_no) = ?';
            $params[] = mb_strtolower($c['reference_no']);
        }
        if ($c['utr_cheque_no'] !== null) {
            $match[] = 'LOWER(utr_cheque_no) = ?';
            $params[] = mb_strtolower($c['utr_cheque_no']);
        }
        if ($c['counterparty_name'] !== null) {
            $match[] = 'LOWER(counterparty_name) = ?';
            $params[] = mb_strtolower($c['counterparty_name']);
        }
        $sql = "SELECT transaction_no, transaction_date FROM bank_transactions WHERE bank_account_id = ? AND direction = ? AND amount = ? AND transaction_date BETWEEN ? AND ?
                AND status NOT IN ('rejected', 'cancelled') AND type <> 'reversal'" . ($match !== [] ? ' AND (' . implode(' OR ', $match) . ')' : ' AND reference_no IS NULL AND utr_cheque_no IS NULL')
            . ($exceptId ? ' AND id <> ' . $exceptId : '') . ' LIMIT 1';
        $dup = $this->db->first($sql, $params);
        if ($dup !== null) {
            throw new ValidationException(['amount' => ["Possible duplicate of {$dup['transaction_no']} (" . format_date($dup['transaction_date']) . ', same amount' . ($match !== [] ? ' and reference/counterparty' : '') . '). If it is a separate transaction, tick the confirmation and save again.'],
                'duplicate_ok' => ['Confirm that this is not a duplicate.']]);
        }
    }

    /** @return array<string, mixed> */
    private function account(int $id): array
    {
        $acc = $this->db->first('SELECT * FROM bank_accounts WHERE id = ?', [$id]);
        if ($acc === null) {
            throw ValidationException::withMessage('bank_account_id', 'Choose a bank account.');
        }
        return $acc;
    }

    /** @param array<string, mixed> $txn @param list<string> $from @param array<string, mixed> $set */
    private function transition(array $txn, array $from, array $set): void
    {
        $in = implode(',', array_fill(0, count($from), '?'));
        $changed = $this->db->execute('UPDATE bank_transactions SET ' . implode(', ', array_map(static fn (string $k): string => "{$k} = ?", array_keys($set)))
            . ", updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status IN ({$in})", array_merge(array_values($set), [now(), (int) $txn['id']], $from));
        if ($changed !== 1) {
            throw new DomainRuleException("{$txn['transaction_no']} was changed by someone else. Reload and try again.");
        }
    }

    public function nextNo(): string
    {
        $max = (int) $this->db->scalar("SELECT COALESCE(MAX(CAST(SUBSTR(transaction_no, 5) AS UNSIGNED)), 0) FROM bank_transactions WHERE transaction_no LIKE 'BTX-%'");
        return 'BTX-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    public static function auditView(array $t): array
    {
        return array_intersect_key($t, array_flip(['transaction_no', 'bank_account_id', 'type', 'direction', 'amount', 'transaction_date', 'reference_no', 'utr_cheque_no', 'counterparty_name',
            'partner_id', 'gst_registration_id', 'business_activity_id', 'capital_transaction_id', 'status', 'description']));
    }
}
