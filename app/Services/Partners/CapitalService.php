<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Partner capital register: contributions, withdrawals and adjustments.
 *
 * Lifecycle:  draft ─submit→ pending_approval ─approve→ approved ─post→ posted ─reverse→ reversed
 *                │                  └─reject→ rejected (editable → back to draft)
 *                └─cancel→ cancelled
 * Only posted rows affect balances (see CapitalLedger). Posting and reversal
 * run in one DB transaction with optimistic guards, so a row can be posted
 * or reversed once only. Posted rows are never edited or deleted: they are
 * corrected by a reversal (new row, opposite direction) plus a replacement.
 * Contributions are not revenue and withdrawals are not expenses: they only
 * ever touch the partner capital account.
 */
final class CapitalService
{
    public const SUBJECT = 'capital_transaction';

    /** type => [module, workflow, number prefix, label, default direction] */
    public const TYPES = [
        'contribution' => ['capital_contributions', 'capital_contribution', 'CC', 'Capital contribution', 'credit'],
        'withdrawal' => ['capital_withdrawals', 'partner_withdrawal', 'CW', 'Capital withdrawal', 'debit'],
        'adjustment' => ['capital_adjustments', 'capital_adjustment', 'CA', 'Capital adjustment', null],
        'reversal' => [null, null, 'CR', 'Reversal', null],
    ];
    public const METHODS = ['bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'cheque' => 'Cheque', 'upi' => 'UPI', 'other' => 'Other'];
    public const CATEGORIES = ['opening_balance' => 'Opening capital migration', 'correction' => 'Correction of historical balance', 'reclassification' => 'Approved capital reclassification', 'other' => 'Other'];
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved', 'rejected' => 'Rejected', 'posted' => 'Posted', 'reversed' => 'Reversed', 'cancelled' => 'Cancelled'];

    private static bool $listening = false;

    public function __construct(private readonly Database $db)
    {
    }

    /** Wire approval decisions back to capital rows (called once when the approval engine is created). */
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

    public static function module(string $type): string
    {
        return self::TYPES[$type][0] ?? throw new DomainRuleException('Unknown capital transaction type.');
    }

    // ---------------------------------------------------------------- queries

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT t.*, p.partner_code, p.name AS partner_name, p.status AS partner_status, p.user_id AS partner_user_id, a.account_code,
                    cu.name AS created_by_name, au.name AS approved_by_name, pu.name AS posted_by_name, ru.name AS reversed_by_name,
                    o.transaction_no AS reversal_of_no, o.type AS reversal_of_type, r.transaction_no AS reversed_by_no
             FROM partner_capital_transactions t
             JOIN partners p ON p.id = t.partner_id
             JOIN partner_capital_accounts a ON a.id = t.capital_account_id
             JOIN users cu ON cu.id = t.created_by
             LEFT JOIN users au ON au.id = t.approved_by
             LEFT JOIN users pu ON pu.id = t.posted_by
             LEFT JOIN users ru ON ru.id = t.reversed_by
             LEFT JOIN partner_capital_transactions o ON o.id = t.reversal_of_id
             LEFT JOIN partner_capital_transactions r ON r.id = t.reversed_by_id
             WHERE t.id = ?',
            [$id]
        );
    }

    /**
     * @param array{partner?: int|string, status?: string, from?: string, to?: string, q?: string} $filters
     * @param array{0: string, 1: list<mixed>} $scope DataAccess condition on alias t
     */
    public function paginate(string $type, array $filters, int $page, int $perPage, array $scope): Paginator
    {
        [$where, $params] = $this->where($type, $filters, $scope);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM partner_capital_transactions t JOIN partners p ON p.id = t.partner_id WHERE {$where}", $params);
        $perPage = max(1, min(500, $perPage));
        $offset = (max(1, $page) - 1) * $perPage;
        $rows = $this->db->select(
            "SELECT t.*, p.partner_code, p.name AS partner_name FROM partner_capital_transactions t JOIN partners p ON p.id = t.partner_id
             WHERE {$where} ORDER BY t.transaction_date DESC, t.id DESC LIMIT {$perPage} OFFSET {$offset}",
            $params
        );
        return new Paginator($rows, $total, max(1, $page), $perPage);
    }

    /** @param array<string, mixed> $filters @param array{0: string, 1: list<mixed>} $scope @return list<array<string, mixed>> */
    public function all(string $type, array $filters, array $scope, int $limit = 10000): array
    {
        [$where, $params] = $this->where($type, $filters, $scope);
        return $this->db->select(
            "SELECT t.*, p.partner_code, p.name AS partner_name, cu.name AS created_by_name, au.name AS approved_by_name
             FROM partner_capital_transactions t JOIN partners p ON p.id = t.partner_id JOIN users cu ON cu.id = t.created_by LEFT JOIN users au ON au.id = t.approved_by
             WHERE {$where} ORDER BY t.transaction_date DESC, t.id DESC LIMIT " . max(1, $limit),
            $params
        );
    }

    /** Totals by status (paise) for the filtered list. @param array<string, mixed> $filters @param array{0: string, 1: list<mixed>} $scope @return array<string, array{count: int, amount: int}> */
    public function totals(string $type, array $filters, array $scope): array
    {
        $filters['status'] = '';
        [$where, $params] = $this->where($type, $filters, $scope);
        $out = [];
        foreach ($this->db->select("SELECT t.status, COUNT(*) AS n, SUM(ROUND(t.amount * 100)) AS amt FROM partner_capital_transactions t JOIN partners p ON p.id = t.partner_id WHERE {$where} GROUP BY t.status", $params) as $r) {
            $out[$r['status']] = ['count' => (int) $r['n'], 'amount' => CapitalLedger::int($r['amt'])];
        }
        return $out;
    }

    // ---------------------------------------------------------------- drafts

    /**
     * @param array{partner_id: int, amount: int, transaction_date: string, payment_method?: ?string, payment_reference?: ?string,
     *              payment_source_ref?: ?string, notes?: ?string, reason?: ?string, adjustment_category?: ?string, direction?: ?string} $data amount in paise
     * @param array<string, mixed> $actor
     */
    public function createDraft(string $type, array $data, array $actor): int
    {
        if ($type === 'reversal') {
            throw new DomainRuleException('Reversals are created by reversing a posted entry.');
        }
        $partner = $this->partnerForEntry((int) $data['partner_id']);
        $row = $this->clean($type, $data);
        $id = $this->db->transaction(function (Database $db) use ($type, $row, $partner, $actor): int {
            $now = now();
            return $db->insert('partner_capital_transactions', $row + [
                'transaction_no' => $this->nextNumber($type, $row['transaction_date']),
                'partner_id' => (int) $partner['id'], 'capital_account_id' => (int) $partner['capital_account_id'], 'type' => $type,
                'status' => 'draft', 'created_by' => (int) $actor['id'], 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        });
        $txn = $this->find($id);
        $this->audit('created', $txn, null, self::auditView($txn), "Recorded draft {$txn['transaction_no']} for {$txn['partner_name']}", (int) $actor['id']);
        return $id;
    }

    /** Edit a draft (or a rejected entry, which returns to draft). @param array<string, mixed> $txn @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function updateDraft(array $txn, array $data, array $actor): void
    {
        if (!in_array($txn['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only draft or rejected entries can be edited. Posted entries are corrected by reversal.');
        }
        if ((int) $data['partner_id'] !== (int) $txn['partner_id']) {
            $this->partnerForEntry((int) $data['partner_id']);
        }
        $row = $this->clean((string) $txn['type'], $data);
        $partner = $this->partnerForEntry((int) $data['partner_id']);
        $changed = $this->db->execute(
            'UPDATE partner_capital_transactions SET partner_id = ?, capital_account_id = ?, amount = ?, transaction_date = ?, payment_method = ?, payment_reference = ?, payment_source_ref = ?,
                notes = ?, reason = ?, adjustment_category = ?, direction = ?, status = \'draft\', rejection_reason = NULL, approval_request_id = NULL,
                updated_by = ?, updated_at = ?, lock_version = lock_version + 1
             WHERE id = ? AND lock_version = ? AND status IN (\'draft\',\'rejected\')',
            [(int) $partner['id'], (int) $partner['capital_account_id'], $row['amount'], $row['transaction_date'], $row['payment_method'], $row['payment_reference'], $row['payment_source_ref'],
                $row['notes'], $row['reason'], $row['adjustment_category'], $row['direction'], (int) $actor['id'], now(), (int) $txn['id'], (int) $txn['lock_version']]
        );
        if ($changed !== 1) {
            throw new DomainRuleException('This entry was changed by someone else. Please reload and try again.');
        }
        $new = $this->find((int) $txn['id']);
        $this->audit('updated', $new, self::auditView($txn), self::auditView($new), "Edited {$txn['status']} entry {$txn['transaction_no']}", (int) $actor['id']);
    }

    /** @param array<string, mixed> $txn @param array<string, mixed> $actor */
    public function cancel(array $txn, array $actor, ?string $reason = null): void
    {
        if (!in_array($txn['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only draft or rejected entries can be cancelled.');
        }
        $this->transition($txn, ['draft', 'rejected'], ['status' => 'cancelled', 'cancelled_by' => (int) $actor['id'], 'cancelled_at' => now(), 'updated_by' => (int) $actor['id']]);
        $this->audit('cancelled', $txn, ['status' => $txn['status']], ['status' => 'cancelled', 'reason' => $reason], "Cancelled {$txn['transaction_no']}", (int) $actor['id']);
    }

    // ---------------------------------------------------------------- approval

    /** Submit a draft for approval through the Phase 2 workflow engine. @param array<string, mixed> $txn @param array<string, mixed> $actor */
    public function submit(array $txn, array $actor): array
    {
        if ($txn['status'] !== 'draft') {
            throw new DomainRuleException('Only drafts can be submitted for approval.');
        }
        $this->partnerForEntry((int) $txn['partner_id']);
        $amount = Money::fromDb($txn['amount']);
        if ($txn['direction'] === 'debit') {
            $this->assertCapacity($txn, $amount, $actor, 'submit');
        }
        [, $workflow] = self::TYPES[$txn['type']];
        // Mark pending first so an auto-approval (inactive workflow) can move it straight to approved.
        $this->transition($txn, ['draft'], ['status' => 'pending_approval', 'submitted_at' => now(), 'updated_by' => (int) $actor['id']]);
        try {
            $request = App::approvals()->submit($workflow, self::SUBJECT, (int) $txn['id'], Money::toFloat($amount),
                self::TYPES[$txn['type']][3] . " {$txn['transaction_no']} — {$txn['partner_name']}", $actor);
        } catch (\Throwable $e) {
            $this->db->execute("UPDATE partner_capital_transactions SET status = 'draft', submitted_at = NULL, lock_version = lock_version + 1 WHERE id = ? AND status = 'pending_approval'", [$txn['id']]);
            throw $e;
        }
        $this->db->execute('UPDATE partner_capital_transactions SET approval_request_id = ? WHERE id = ?', [$request['id'], $txn['id']]);
        $this->audit('submitted', $txn, ['status' => 'draft'], ['status' => 'pending_approval', 'approval_request' => (int) $request['id']], "Submitted {$txn['transaction_no']} for approval", (int) $actor['id']);
        return $this->find((int) $txn['id']);
    }

    /** Approval engine callback. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $txn = $this->find((int) $request['subject_id']);
        if ($txn === null || $txn['status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = $last !== null ? (int) $last['acted_by'] : null;
        $updates = match ($status) {
            'approved' => ['status' => 'approved', 'approved_by' => $by, 'approved_at' => now(), 'approval_request_id' => (int) $request['id']],
            'rejected' => ['status' => 'rejected', 'rejection_reason' => $last['comment'] ?? null, 'approval_request_id' => (int) $request['id']],
            'cancelled' => ['status' => 'draft', 'submitted_at' => null, 'approval_request_id' => null],
            default => null,
        };
        if ($updates === null) {
            return;
        }
        $this->transition($txn, ['pending_approval'], $updates);
        $this->audit($status === 'cancelled' ? 'withdrawn' : $status, $txn, ['status' => 'pending_approval'], ['status' => $updates['status']] + ($status === 'rejected' ? ['reason' => $updates['rejection_reason']] : []),
            ($status === 'cancelled' ? 'Approval request withdrawn for ' : ucfirst($status) . ' ') . $txn['transaction_no'], $by);
    }

    // ---------------------------------------------------------------- posting

    /**
     * Post an approved entry to the capital ledger (and queue it for accounting).
     * Idempotent guard: UPDATE … WHERE status = 'approved' must change exactly one row,
     * and the accounting queue has a unique key per source event.
     * @param array<string, mixed> $txn @param array<string, mixed> $actor
     */
    public function post(array $txn, array $actor): void
    {
        if ($txn['status'] === 'posted' || $txn['status'] === 'reversed') {
            throw new DomainRuleException("{$txn['transaction_no']} is already posted.");
        }
        if ($txn['status'] !== 'approved') {
            throw new DomainRuleException('Only approved entries can be posted.');
        }
        $amount = Money::fromDb($txn['amount']);
        $this->db->transaction(function (Database $db) use ($txn, $actor, $amount): void {
            if ($txn['direction'] === 'debit') {
                $this->assertCapacity($txn, $amount, $actor, 'post', postedOnly: true);
            }
            $changed = $db->execute(
                "UPDATE partner_capital_transactions SET status = 'posted', posted_by = ?, posted_at = ?, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status = 'approved'",
                [(int) $actor['id'], now(), now(), (int) $txn['id']]
            );
            if ($changed !== 1) {
                throw new DomainRuleException("{$txn['transaction_no']} was already posted or changed by someone else.");
            }
            $this->queue($txn, 'capital.' . $txn['type'] . '.posted', $amount);
        });
        $this->audit('posted', $txn, ['status' => 'approved'], ['status' => 'posted', 'amount' => Money::toDb($amount), 'balance_after' => Money::toDb(App::capitalLedger()->balance((int) $txn['partner_id']))],
            "Posted {$txn['transaction_no']} (" . Money::format($amount) . ") to the capital ledger of {$txn['partner_name']}", (int) $actor['id']);
    }

    /**
     * Reverse a posted entry: a new posted 'reversal' row in the opposite
     * direction is created and the original is marked 'reversed'. Both stay
     * in the ledger, so the history is complete and the pair nets to zero.
     * @param array<string, mixed> $txn @param array<string, mixed> $actor
     */
    public function reverse(array $txn, string $reason, string $date, array $actor): int
    {
        if ($txn['type'] === 'reversal') {
            throw new DomainRuleException('A reversal cannot itself be reversed. Record a new entry instead.');
        }
        if ($txn['status'] === 'reversed') {
            throw new DomainRuleException("{$txn['transaction_no']} has already been reversed.");
        }
        if ($txn['status'] !== 'posted') {
            throw new DomainRuleException('Only posted entries can be reversed. Drafts and rejected entries can simply be cancelled.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reversal reason is required.');
        }
        if ($date < (string) $txn['transaction_date']) {
            throw ValidationException::withMessage('reversal_date', 'The reversal date cannot be before the original entry date.');
        }
        if ($date > BusinessDate::today()) {
            throw ValidationException::withMessage('reversal_date', 'The reversal date cannot be in the future.');
        }
        $amount = Money::fromDb($txn['amount']);
        $direction = $txn['direction'] === 'credit' ? 'debit' : 'credit';
        $id = $this->db->transaction(function (Database $db) use ($txn, $actor, $amount, $direction, $reason, $date): int {
            if ($direction === 'debit') {
                // Reversing a credit takes capital out again: same balance protection as a withdrawal.
                $this->assertCapacity($txn, $amount, $actor, 'reverse', postedOnly: true);
            }
            $now = now();
            $id = $db->insert('partner_capital_transactions', [
                'transaction_no' => $this->nextNumber('reversal', $date), 'partner_id' => (int) $txn['partner_id'], 'capital_account_id' => (int) $txn['capital_account_id'],
                'type' => 'reversal', 'direction' => $direction, 'amount' => Money::toDb($amount), 'transaction_date' => $date,
                'payment_method' => $txn['payment_method'], 'payment_reference' => $txn['payment_reference'], 'payment_source_ref' => $txn['payment_source_ref'],
                'bank_account_id' => $txn['bank_account_id'], 'adjustment_category' => $txn['adjustment_category'], 'reason' => mb_substr($reason, 0, 500),
                'status' => 'posted', 'reversal_of_id' => (int) $txn['id'], 'created_by' => (int) $actor['id'],
                'approved_by' => (int) $actor['id'], 'approved_at' => $now, 'posted_by' => (int) $actor['id'], 'posted_at' => $now,
                'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $changed = $db->execute(
                "UPDATE partner_capital_transactions SET status = 'reversed', reversed_by_id = ?, reversed_by = ?, reversed_at = ?, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status = 'posted'",
                [$id, (int) $actor['id'], $now, $now, (int) $txn['id']]
            );
            if ($changed !== 1) {
                throw new DomainRuleException("{$txn['transaction_no']} was already reversed or changed by someone else.");
            }
            $reversal = $this->find($id);
            $this->queue($reversal, 'capital.reversal.posted', $amount, $txn);
            return $id;
        });
        $rev = $this->find($id);
        $this->audit('reversed', $txn, ['status' => 'posted'], ['status' => 'reversed', 'reversal' => $rev['transaction_no'], 'reason' => $reason], "Reversed {$txn['transaction_no']} with {$rev['transaction_no']}", (int) $actor['id']);
        $this->audit('reversal_posted', $rev, null, self::auditView($rev), "Posted reversal {$rev['transaction_no']} of {$txn['transaction_no']}", (int) $actor['id']);
        return $id;
    }

    // ---------------------------------------------------------------- rules

    /**
     * Withdrawal / debit protection. On submit, debits already pending or approved
     * are reserved too; on posting, only the posted balance counts.
     * Exceeding is allowed only when the exception policy setting is on AND the
     * actor holds capital_withdrawals.override_limit — and it is always audited.
     * @param array<string, mixed> $txn @param array<string, mixed> $actor
     */
    public function assertCapacity(array $txn, int $amount, array $actor, string $stage, bool $postedOnly = false): void
    {
        $ledger = App::capitalLedger();
        $available = $postedOnly ? $ledger->balance((int) $txn['partner_id']) : $ledger->available((int) $txn['partner_id'], (int) $txn['id']);
        if ($amount <= $available) {
            return;
        }
        if ($this->mayOverdraw($actor)) {
            App::activity()->log('capital.overdraw_exception', "Exception policy used at {$stage}: {$txn['transaction_no']} of " . Money::format($amount) . ' exceeds available capital ' . Money::format($available),
                'capital_transaction', (int) $txn['id'], module: 'capital_withdrawals', newValues: ['amount' => Money::toDb($amount), 'available' => Money::toDb($available), 'stage' => $stage]);
            return;
        }
        throw new DomainRuleException('Insufficient capital: ' . Money::format($amount) . ' exceeds the eligible available capital of ' . Money::format(max(0, $available)) . ' for this partner'
            . ($postedOnly ? '.' : ' (posted balance minus withdrawals already awaiting approval or posting).'));
    }

    /** @param array<string, mixed> $actor */
    public function mayOverdraw(array $actor): bool
    {
        return (bool) App::settings()->get('allow_capital_overdraw', false) && App::gate()->allows($actor, 'capital_withdrawals.override_limit');
    }

    /** Audit-safe snapshot. @param array<string, mixed> $t @return array<string, mixed> */
    public static function auditView(array $t): array
    {
        return array_intersect_key($t, array_flip(['transaction_no', 'type', 'direction', 'amount', 'transaction_date', 'payment_method', 'payment_reference', 'payment_source_ref',
            'adjustment_category', 'reason', 'notes', 'status', 'partner_id']));
    }

    /** @return array<string, mixed> partner with capital_account_id, must accept new entries */
    private function partnerForEntry(int $partnerId): array
    {
        $p = $this->db->first('SELECT p.*, c.id AS capital_account_id FROM partners p JOIN partner_capital_accounts c ON c.partner_id = p.id WHERE p.id = ?', [$partnerId]);
        if ($p === null) {
            throw ValidationException::withMessage('partner_id', 'Select a valid partner.');
        }
        if ($p['status'] !== 'active') {
            throw ValidationException::withMessage('partner_id', "{$p['name']} is " . $p['status'] . '. Capital entries can only be recorded and submitted for active partners.');
        }
        return $p;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function clean(string $type, array $data): array
    {
        $amount = (int) $data['amount'];
        if ($amount <= 0 || $amount > Money::MAX_PAISE) {
            throw ValidationException::withMessage('amount', 'Enter an amount greater than zero.');
        }
        $date = (string) $data['transaction_date'];
        if ($date > BusinessDate::today()) {
            throw ValidationException::withMessage('transaction_date', 'The date cannot be in the future.');
        }
        $method = $data['payment_method'] ?? null;
        $direction = self::TYPES[$type][4];
        $category = null;
        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';
        if ($type === 'adjustment') {
            $category = (string) ($data['adjustment_category'] ?? '');
            if (!isset(self::CATEGORIES[$category])) {
                throw ValidationException::withMessage('adjustment_category', 'Choose the adjustment type.');
            }
            $direction = (string) ($data['direction'] ?? '');
            if (!in_array($direction, ['credit', 'debit'], true)) {
                throw ValidationException::withMessage('direction', 'Choose whether the adjustment increases or reduces capital.');
            }
            if ($reason === '') {
                throw ValidationException::withMessage('reason', 'An adjustment reason is required.');
            }
            $method = null;
        } else {
            if (!isset(self::METHODS[(string) $method])) {
                throw ValidationException::withMessage('payment_method', 'Choose the payment method.');
            }
            if ($type === 'withdrawal' && $reason === '') {
                throw ValidationException::withMessage('reason', 'A withdrawal reason is required.');
            }
        }
        $opt = static fn (string $k, int $max): ?string => ($v = trim((string) ($data[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        return [
            'amount' => Money::toDb($amount), 'transaction_date' => $date, 'direction' => $direction,
            'payment_method' => $method, 'payment_reference' => $type === 'adjustment' ? null : $opt('payment_reference', 100),
            'payment_source_ref' => $type === 'adjustment' ? null : $opt('payment_source_ref', 150),
            'notes' => $opt('notes', 5000), 'reason' => $reason === '' ? null : mb_substr($reason, 0, 500), 'adjustment_category' => $category,
        ];
    }

    private function nextNumber(string $type, string $date): string
    {
        $prefix = self::TYPES[$type][2] . '-' . substr($date, 0, 4) . '-';
        $max = 0;
        foreach ($this->db->select('SELECT transaction_no FROM partner_capital_transactions WHERE transaction_no LIKE ?', [$prefix . '%']) as $r) {
            $max = max($max, (int) substr((string) $r['transaction_no'], strlen($prefix)));
        }
        return $prefix . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Guarded status change. @param array<string, mixed> $txn @param list<string> $from @param array<string, mixed> $set */
    private function transition(array $txn, array $from, array $set): void
    {
        $cols = [];
        $params = [];
        foreach ($set + ['updated_at' => now()] as $k => $v) {
            $cols[] = $this->db->identifier($k) . ' = ?';
            $params[] = $v;
        }
        $params[] = (int) $txn['id'];
        $in = implode(',', array_fill(0, count($from), '?'));
        $changed = $this->db->execute('UPDATE partner_capital_transactions SET ' . implode(', ', $cols) . ", lock_version = lock_version + 1 WHERE id = ? AND status IN ({$in})", array_merge($params, $from));
        if ($changed !== 1) {
            throw new DomainRuleException('This entry was changed by someone else. Please reload and try again.');
        }
    }

    /**
     * Queue the accounting effect (Phase 12). Contributions: Dr bank/cash, Cr partner capital.
     * Withdrawals: Dr partner capital, Cr bank/cash. Adjustments use a clearing reference
     * for Phase 12 to map. Reversals swap the original's sides.
     * @param array<string, mixed> $txn @param array<string, mixed>|null $original
     */
    private function queue(array $txn, string $event, int $amount, ?array $original = null): void
    {
        $capital = 'partner_capital:' . $txn['account_code'];
        $source = static function (array $t): string {
            if ($t['type'] === 'adjustment' || ($t['type'] === 'reversal' && $t['adjustment_category'] !== null)) {
                return 'capital_adjustment:' . $t['adjustment_category'];
            }
            return 'payment_source:' . ($t['payment_method'] ?? 'other') . ($t['bank_account_id'] ? ':bank_account#' . $t['bank_account_id'] : ($t['payment_source_ref'] ? ':' . $t['payment_source_ref'] : ''));
        };
        $other = $source($txn);
        [$debit, $credit] = $txn['direction'] === 'credit' ? [$other, $capital] : [$capital, $other];
        App::postingQueue()->enqueue(self::SUBJECT, (int) $txn['id'], $event, (string) $txn['transaction_date'], $amount, $debit, $credit, [
            'transaction_no' => $txn['transaction_no'], 'partner_id' => (int) $txn['partner_id'], 'type' => $txn['type'], 'direction' => $txn['direction'],
            'payment_reference' => $txn['payment_reference'], 'reversal_of' => $original['transaction_no'] ?? null,
            'nature' => 'partner_capital', // never revenue or expense
        ]);
    }

    /** @param array<string, mixed> $txn @param array<string, mixed>|null $old @param array<string, mixed>|null $new */
    private function audit(string $event, array $txn, ?array $old, ?array $new, string $description, ?int $userId = null): void
    {
        $module = $txn['type'] === 'reversal' ? self::module((string) ($txn['reversal_of_type'] ?? 'contribution')) : self::module((string) $txn['type']);
        App::activity()->log('capital.' . $event, $description, 'capital_transaction', (int) $txn['id'], userId: $userId, module: $module, oldValues: $old, newValues: $new);
    }

    /** @param array<string, mixed> $filters @param array{0: string, 1: list<mixed>} $scope @return array{0: string, 1: list<mixed>} */
    private function where(string $type, array $filters, array $scope): array
    {
        [$where, $params] = $scope;
        $sql = [$where];
        if ($type === 'adjustment') {
            // Reversals of adjustments are listed with adjustments.
            $sql[] = "(t.type = 'adjustment' OR (t.type = 'reversal' AND t.adjustment_category IS NOT NULL))";
        } elseif ($type !== 'all') {
            $sql[] = '(t.type = ? OR (t.type = \'reversal\' AND t.reversal_of_id IN (SELECT id FROM partner_capital_transactions WHERE type = ?)))';
            array_push($params, $type, $type);
        }
        if (!empty($filters['partner'])) {
            $sql[] = 't.partner_id = ?';
            $params[] = (int) $filters['partner'];
        }
        $status = (string) ($filters['status'] ?? '');
        if ($status === 'pending') {
            $sql[] = "t.status IN ('pending_approval','approved')";
        } elseif (isset(self::STATUSES[$status])) {
            $sql[] = 't.status = ?';
            $params[] = $status;
        }
        foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
            if (!empty($filters[$k]) && \App\Core\Validator::isDate((string) $filters[$k])) {
                $sql[] = "t.transaction_date {$op} ?";
                $params[] = (string) $filters[$k];
            }
        }
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
            $sql[] = "(LOWER(t.transaction_no) LIKE ? ESCAPE '!' OR LOWER(COALESCE(t.payment_reference, '')) LIKE ? ESCAPE '!' OR LOWER(p.name) LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        return [implode(' AND ', $sql), $params];
    }
}
