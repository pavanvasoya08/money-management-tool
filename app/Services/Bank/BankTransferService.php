<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Internal transfers between the company's own bank accounts.
 *
 * One movement = one bank_transfers row. Posting creates exactly two ledger rows in one
 * database transaction — transfer_out (debit, source) and transfer_in (credit, destination) —
 * both pointing to the transfer (unique per side, so a second posting is impossible).
 * Transfers are neither income nor expense. Reversal reverses both sides together.
 */
final class BankTransferService
{
    public const SUBJECT = 'bank_transfer';
    public const STATUSES = BankTransactionService::STATUSES;
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

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT tr.*, fa.display_name AS from_name, fa.account_code AS from_code, fa.account_number_last4 AS from_last4, fa.is_central_funding AS from_central,
                    ta.display_name AS to_name, ta.account_code AS to_code, ta.account_number_last4 AS to_last4, ta.is_central_funding AS to_central,
                    g.gstin, act.name AS activity_name, cu.name AS created_by_name, au.name AS approved_by_name, pu.name AS posted_by_name, ru.name AS reversed_by_name
             FROM bank_transfers tr JOIN bank_accounts fa ON fa.id = tr.from_account_id JOIN bank_accounts ta ON ta.id = tr.to_account_id
             LEFT JOIN gst_registrations g ON g.id = tr.gst_registration_id LEFT JOIN gst_business_activities act ON act.id = tr.business_activity_id
             LEFT JOIN users cu ON cu.id = tr.created_by LEFT JOIN users au ON au.id = tr.approved_by LEFT JOIN users pu ON pu.id = tr.posted_by LEFT JOIN users ru ON ru.id = tr.reversed_by
             WHERE tr.id = ?',
            [$id]
        );
    }

    /** The ledger rows of a transfer (two when posted, four after reversal). @return list<array<string, mixed>> */
    public function entries(int $transferId): array
    {
        return $this->db->select(
            'SELECT t.*, a.display_name AS account_name, a.account_code FROM bank_transactions t JOIN bank_accounts a ON a.id = t.bank_account_id
             WHERE t.transfer_id = ? OR t.reversal_of_id IN (SELECT id FROM bank_transactions WHERE transfer_id = ?) ORDER BY t.id',
            [$transferId, $transferId]
        );
    }

    /**
     * Visible when the user can see the source or destination account.
     * @param array<string, mixed> $f status, account, from, to, q, central
     * @param array{0: string, 1: list<mixed>} $fromScope constraint on alias fa @param array{0: string, 1: list<mixed>} $toScope on alias ta
     * @return array{0: string, 1: list<mixed>}
     */
    public function filterSql(array $f, array $fromScope, array $toScope): array
    {
        $sql = ['((' . $fromScope[0] . ') OR (' . $toScope[0] . '))'];
        $params = array_merge($fromScope[1], $toScope[1]);
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $sql[] = 'tr.status = ?';
            $params[] = $status;
        } elseif ($status === 'open') {
            $sql[] = "tr.status IN ('draft', 'pending_approval', 'approved')";
        }
        if ((int) ($f['account'] ?? 0) > 0) {
            $sql[] = '(tr.from_account_id = ? OR tr.to_account_id = ?)';
            array_push($params, (int) $f['account'], (int) $f['account']);
        }
        foreach (['gst' => 'tr.gst_registration_id = ?', 'activity' => 'tr.business_activity_id = ?'] as $k => $cond) {
            if ((int) ($f[$k] ?? 0) > 0) {
                $sql[] = $cond;
                $params[] = (int) $f[$k];
            }
        }
        foreach (['from' => 'tr.transfer_date >= ?', 'to' => 'tr.transfer_date <= ?'] as $k => $cond) {
            if (BusinessDate::isValid((string) ($f[$k] ?? ''))) {
                $sql[] = $cond;
                $params[] = $f[$k];
            }
        }
        if (($f['central'] ?? '') === '1') {
            $sql[] = '(fa.is_central_funding = 1 OR ta.is_central_funding = 1)';
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $sql[] = "(LOWER(tr.transfer_no) LIKE ? OR LOWER(COALESCE(tr.reference_no, '')) LIKE ? OR LOWER(tr.purpose) LIKE ?)";
            array_push($params, $like, $like, $like);
        }
        return [implode(' AND ', $sql), $params];
    }

    /** @param array<string, mixed> $f @param array{0: string, 1: list<mixed>} $fromScope @param array{0: string, 1: list<mixed>} $toScope */
    public function paginate(array $f, int $page, int $perPage, array $fromScope, array $toScope): Paginator
    {
        [$w, $p] = $this->filterSql($f, $fromScope, $toScope);
        $base = 'FROM bank_transfers tr JOIN bank_accounts fa ON fa.id = tr.from_account_id JOIN bank_accounts ta ON ta.id = tr.to_account_id';
        $total = (int) $this->db->scalar("SELECT COUNT(*) {$base} WHERE {$w}", $p);
        $rows = $this->db->select("SELECT tr.*, fa.display_name AS from_name, fa.account_code AS from_code, fa.is_central_funding AS from_central, ta.display_name AS to_name, ta.account_code AS to_code, ta.is_central_funding AS to_central
            {$base} WHERE {$w} ORDER BY tr.transfer_date DESC, tr.id DESC LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $p);
        return new Paginator($rows, $total, $page, $perPage);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor @return array<string, mixed> */
    public function create(array $data, array $actor, bool $submit = false): array
    {
        $c = $this->validate($data);
        [$from, $to] = [$this->account($c['from_account_id']), $this->account($c['to_account_id'])];
        BankAccountService::assertUsable($from, 'outgoing transfers');
        BankAccountService::assertUsable($to, 'incoming transfers');
        if ($from['currency'] !== $to['currency']) {
            throw ValidationException::withMessage('to_account_id', 'Both accounts must use the same currency.');
        }
        foreach ([$from, $to] as $a) {
            if ($c['transfer_date'] < $a['opening_balance_date']) {
                throw ValidationException::withMessage('transfer_date', "The date is before the opening-balance date of {$a['display_name']}.");
            }
        }
        if (empty($data['duplicate_ok'])) {
            $days = (int) App::settings()->get('bank_duplicate_window_days', 3);
            $dup = $this->db->first("SELECT transfer_no FROM bank_transfers WHERE from_account_id = ? AND to_account_id = ? AND amount = ? AND transfer_date BETWEEN ? AND ? AND status NOT IN ('rejected', 'cancelled')"
                . ($c['reference_no'] !== null ? ' AND (reference_no IS NULL OR LOWER(reference_no) = ?)' : '') . ' LIMIT 1',
                array_merge([$from['id'], $to['id'], $c['amount'], BusinessDate::addDays($c['transfer_date'], -$days), BusinessDate::addDays($c['transfer_date'], $days)], $c['reference_no'] !== null ? [mb_strtolower($c['reference_no'])] : []));
            if ($dup !== null) {
                throw new ValidationException(['amount' => ["Possible duplicate of transfer {$dup['transfer_no']} (same accounts and amount). If this is a separate movement, tick the confirmation and save again."], 'duplicate_ok' => ['Confirm that this is not a duplicate.']]);
            }
        }
        $id = $this->db->insert('bank_transfers', $c + ['transfer_no' => $this->nextNo(), 'status' => 'draft', 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        $tr = $this->find($id);
        App::activity()->log('bank.transfer_created', "Recorded transfer {$tr['transfer_no']}: " . Money::format(Money::fromDb($tr['amount'])) . " from {$from['display_name']} to {$to['display_name']}", 'bank_transfer', $id,
            module: 'bank_accounts', newValues: $this->auditView($tr));
        return $submit ? $this->submit($tr, $actor) : $tr;
    }

    /** @param array<string, mixed> $tr @param array<string, mixed> $actor */
    public function submit(array $tr, array $actor): array
    {
        if ($tr['status'] !== 'draft') {
            throw new DomainRuleException('Only draft transfers can be submitted.');
        }
        $from = $this->account((int) $tr['from_account_id']);
        BankAccountService::assertUsable($from, 'outgoing transfers');
        BankAccountService::assertUsable($this->account((int) $tr['to_account_id']), 'incoming transfers');
        $amount = Money::fromDb($tr['amount']);
        $this->assertCapacity($from, $amount, (int) $tr['id'], false);
        $this->transition($tr, ['draft'], ['status' => 'pending_approval', 'submitted_at' => now(), 'posting_error' => null, 'updated_by' => (int) $actor['id']]);
        try {
            $req = App::approvals()->submit('bank_transfer', self::SUBJECT, (int) $tr['id'], Money::toFloat($amount),
                "Transfer {$tr['transfer_no']}: {$tr['from_name']} → {$tr['to_name']} — " . Money::format($amount), $actor, 'bank_accounts.manage_transfers');
        } catch (\Throwable $e) {
            $this->db->execute("UPDATE bank_transfers SET status = 'draft', submitted_at = NULL WHERE id = ? AND status = 'pending_approval'", [$tr['id']]);
            throw $e;
        }
        $this->db->execute('UPDATE bank_transfers SET approval_request_id = ? WHERE id = ?', [$req['id'], $tr['id']]);
        App::activity()->log('bank.transfer_submitted', "Submitted transfer {$tr['transfer_no']} for approval", 'bank_transfer', (int) $tr['id'], module: 'bank_accounts', newValues: ['approval_request' => (int) $req['id']]);
        return $this->find((int) $tr['id']) ?? $tr;
    }

    /** @param array<string, mixed> $tr @param array<string, mixed> $actor */
    public function cancel(array $tr, array $actor): void
    {
        if (!in_array($tr['status'], ['draft', 'rejected'], true)) {
            throw new DomainRuleException('Only draft or rejected transfers can be cancelled.');
        }
        $this->transition($tr, ['draft', 'rejected'], ['status' => 'cancelled', 'updated_by' => (int) $actor['id']]);
        App::activity()->log('bank.transfer_cancelled', "Cancelled transfer {$tr['transfer_no']}", 'bank_transfer', (int) $tr['id'], module: 'bank_accounts');
    }

    /** @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $tr = $this->find((int) $request['subject_id']);
        if ($tr === null || $tr['status'] !== 'pending_approval') {
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
        $this->transition($tr, ['pending_approval'], $updates);
        App::activity()->log('bank.transfer_' . ($status === 'cancelled' ? 'withdrawn' : $status), ucfirst($status === 'cancelled' ? 'withdrawn' : $status) . " transfer {$tr['transfer_no']}", 'bank_transfer', (int) $tr['id'],
            module: 'bank_accounts', newValues: ['status' => $updates['status']], userId: $by);
        if ($status === 'approved') {
            try {
                $this->post($this->find((int) $tr['id']), ['id' => $by], true);
            } catch (DomainRuleException $e) {
                $this->db->update('bank_transfers', ['posting_error' => mb_substr($e->getMessage(), 0, 255)], ['id' => $tr['id']]);
            }
        }
    }

    /**
     * Post both sides atomically: lock both accounts (ascending id), re-check the source's
     * posted balance, move approved → posted (guarded) and insert the two linked ledger rows.
     * @param array<string, mixed> $tr @param array<string, mixed> $actor
     */
    public function post(array $tr, array $actor, bool $automatic = false): void
    {
        if (in_array($tr['status'], ['posted', 'reversed'], true)) {
            throw new DomainRuleException("Transfer {$tr['transfer_no']} is already posted.");
        }
        if ($tr['status'] !== 'approved') {
            throw new DomainRuleException('Only approved transfers can be posted.');
        }
        $amount = Money::fromDb($tr['amount']);
        $ledger = new BankLedgerService($this->db);
        $txns = new BankTransactionService($this->db);
        $this->db->transaction(function (Database $db) use ($tr, $actor, $amount, $ledger, $txns): void {
            $ledger->lock([(int) $tr['from_account_id'], (int) $tr['to_account_id']]);
            $from = $this->account((int) $tr['from_account_id']);
            BankAccountService::assertUsable($from, 'outgoing transfers');
            BankAccountService::assertUsable($this->account((int) $tr['to_account_id']), 'incoming transfers');
            $this->assertCapacity($from, $amount, (int) $tr['id'], true);
            $changed = $db->execute("UPDATE bank_transfers SET status = 'posted', posted_by = ?, posted_at = ?, posting_error = NULL, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status = 'approved'",
                [(int) $actor['id'], now(), now(), (int) $tr['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException("Transfer {$tr['transfer_no']} was already posted or changed by someone else.");
            }
            foreach ([['transfer_out', 'debit', (int) $tr['from_account_id'], $tr['to_name']], ['transfer_in', 'credit', (int) $tr['to_account_id'], $tr['from_name']]] as [$type, $dir, $acc, $other]) {
                $db->insert('bank_transactions', [
                    'transaction_no' => $txns->nextNo(), 'bank_account_id' => $acc, 'transaction_date' => $tr['transfer_date'], 'type' => $type, 'direction' => $dir,
                    'amount' => Money::toDb($amount), 'reference_no' => $tr['reference_no'], 'description' => "Transfer {$tr['transfer_no']}: {$tr['purpose']}",
                    'counterparty_name' => $other, 'gst_registration_id' => $tr['gst_registration_id'], 'business_activity_id' => $tr['business_activity_id'],
                    'source_module' => 'transfer', 'transfer_id' => (int) $tr['id'], 'status' => 'posted', 'approved_by' => $tr['approved_by'], 'approved_at' => $tr['approved_at'],
                    'posted_by' => (int) $actor['id'], 'posted_at' => now(), 'created_by' => (int) $tr['created_by'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        App::activity()->log('bank.transfer_posted', "Posted transfer {$tr['transfer_no']} (" . Money::format($amount) . "): {$tr['from_name']} → {$tr['to_name']}" . ($automatic ? ' on approval' : ''), 'bank_transfer', (int) $tr['id'],
            module: 'bank_accounts', newValues: ['status' => 'posted', 'from_balance' => Money::toDb($ledger->balance((int) $tr['from_account_id'])), 'to_balance' => Money::toDb($ledger->balance((int) $tr['to_account_id']))], userId: (int) $actor['id']);
    }

    /** Reverse both sides together. @param array<string, mixed> $tr @param array<string, mixed> $actor */
    public function reverse(array $tr, string $reason, string $date, array $actor): void
    {
        if ($tr['status'] === 'reversed') {
            throw new DomainRuleException("Transfer {$tr['transfer_no']} has already been reversed.");
        }
        if ($tr['status'] !== 'posted') {
            throw new DomainRuleException('Only posted transfers can be reversed.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessage('reason', 'Give a reversal reason (at least 5 characters).');
        }
        if (!BusinessDate::isValid($date) || $date > BusinessDate::today() || $date < $tr['transfer_date']) {
            throw ValidationException::withMessage('reversal_date', 'The reversal date must be between the transfer date and today.');
        }
        $amount = Money::fromDb($tr['amount']);
        $ledger = new BankLedgerService($this->db);
        $txns = new BankTransactionService($this->db);
        $this->db->transaction(function (Database $db) use ($tr, $reason, $date, $actor, $amount, $ledger, $txns): void {
            $ledger->lock([(int) $tr['from_account_id'], (int) $tr['to_account_id']]);
            $to = $this->account((int) $tr['to_account_id']);
            // Reversal takes the money back out of the destination.
            $this->assertCapacity($to, $amount, null, true, 'reverse this transfer (the destination account must still hold the amount)');
            $changed = $db->execute("UPDATE bank_transfers SET status = 'reversed', reversed_by = ?, reversed_at = ?, reversal_reason = ?, updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status = 'posted'",
                [(int) $actor['id'], now(), mb_substr($reason, 0, 500), now(), (int) $tr['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException("Transfer {$tr['transfer_no']} was already reversed or changed by someone else.");
            }
            $sides = $db->select("SELECT * FROM bank_transactions WHERE transfer_id = ? AND status = 'posted' ORDER BY id", [(int) $tr['id']]);
            if (count($sides) !== 2) {
                throw new DomainRuleException('The transfer entries are inconsistent; nothing was reversed.');
            }
            foreach ($sides as $s) {
                $db->execute("UPDATE bank_transactions SET status = 'reversed', reversal_reason = ?, updated_at = ? WHERE id = ? AND status = 'posted'", [mb_substr($reason, 0, 500), now(), $s['id']]);
                $db->insert('bank_transactions', [
                    'transaction_no' => $txns->nextNo(), 'bank_account_id' => (int) $s['bank_account_id'], 'transaction_date' => $date, 'type' => 'reversal',
                    'direction' => $s['direction'] === 'credit' ? 'debit' : 'credit', 'amount' => $s['amount'], 'reference_no' => $s['transaction_no'],
                    'description' => "Reversal of transfer {$tr['transfer_no']}: " . mb_substr($reason, 0, 400), 'counterparty_name' => $s['counterparty_name'],
                    'gst_registration_id' => $s['gst_registration_id'], 'business_activity_id' => $s['business_activity_id'], 'source_module' => 'reversal',
                    'reversal_of_id' => (int) $s['id'], 'reversal_reason' => mb_substr($reason, 0, 500), 'status' => 'posted', 'approved_by' => (int) $actor['id'], 'approved_at' => now(),
                    'posted_by' => (int) $actor['id'], 'posted_at' => now(), 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
        App::activity()->log('bank.transfer_reversed', "Reversed transfer {$tr['transfer_no']} (both sides)", 'bank_transfer', (int) $tr['id'], module: 'bank_accounts',
            oldValues: ['status' => 'posted'], newValues: ['status' => 'reversed', 'reason' => $reason, 'date' => $date]);
    }

    /** @param array<string, mixed> $acc */
    private function assertCapacity(array $acc, int $amount, ?int $exceptTransfer, bool $postedOnly, string $what = 'transfer this amount'): void
    {
        $available = (new BankLedgerService($this->db))->available((int) $acc['id'], null, $exceptTransfer, $postedOnly);
        if ($amount > $available) {
            throw new DomainRuleException('Insufficient balance to ' . $what . ': ' . Money::format($amount) . ' exceeds the available ' . Money::format(max(0, $available)) . ' on ' . $acc['display_name']
                . ((int) $acc['allow_overdraft'] === 1 ? ' (including the overdraft limit).' : '. The account does not allow overdraft.'));
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validate(array $data): array
    {
        foreach (['reference_no', 'purpose', 'notes', 'amount', 'transfer_date'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? ''));
        }
        $v = Validator::make($data, [
            'from_account_id' => 'required|integer|exists:bank_accounts,id', 'to_account_id' => 'required|integer|exists:bank_accounts,id',
            'transfer_date' => 'required|date|before_or_equal:today', 'amount' => 'required|money', 'reference_no' => 'nullable|string|max:100',
            'purpose' => 'required|string|min:3|max:255', 'gst_registration_id' => 'nullable|integer|exists:gst_registrations,id',
            'business_activity_id' => 'nullable|integer|exists:gst_business_activities,id', 'notes' => 'nullable|string|max:2000',
        ], ['from_account_id' => 'Source account', 'to_account_id' => 'Destination account', 'transfer_date' => 'Transfer date', 'gst_registration_id' => 'GST registration', 'business_activity_id' => 'Business activity']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $c = $v->validated();
        if ((int) $c['from_account_id'] === (int) $c['to_account_id']) {
            throw ValidationException::withMessage('to_account_id', 'The destination must be a different account.');
        }
        $amount = Money::parse((string) $c['amount']);
        if ($amount <= 0) {
            throw ValidationException::withMessage('amount', 'The amount must be greater than zero.');
        }
        return ['from_account_id' => (int) $c['from_account_id'], 'to_account_id' => (int) $c['to_account_id'], 'transfer_date' => $c['transfer_date'], 'amount' => Money::toDb($amount),
            'reference_no' => $c['reference_no'] ?: null, 'purpose' => $c['purpose'], 'gst_registration_id' => (int) ($c['gst_registration_id'] ?? 0) ?: null,
            'business_activity_id' => (int) ($c['business_activity_id'] ?? 0) ?: null, 'notes' => $c['notes'] ?: null];
    }

    /** @return array<string, mixed> */
    private function account(int $id): array
    {
        return $this->db->first('SELECT * FROM bank_accounts WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('from_account_id', 'Account not found.');
    }

    /** @param array<string, mixed> $tr @param list<string> $from @param array<string, mixed> $set */
    private function transition(array $tr, array $from, array $set): void
    {
        $in = implode(',', array_fill(0, count($from), '?'));
        $changed = $this->db->execute('UPDATE bank_transfers SET ' . implode(', ', array_map(static fn (string $k): string => "{$k} = ?", array_keys($set)))
            . ", updated_at = ?, lock_version = lock_version + 1 WHERE id = ? AND status IN ({$in})", array_merge(array_values($set), [now(), (int) $tr['id']], $from));
        if ($changed !== 1) {
            throw new DomainRuleException("Transfer {$tr['transfer_no']} was changed by someone else. Reload and try again.");
        }
    }

    private function nextNo(): string
    {
        $max = (int) $this->db->scalar("SELECT COALESCE(MAX(CAST(SUBSTR(transfer_no, 5) AS UNSIGNED)), 0) FROM bank_transfers WHERE transfer_no LIKE 'TRF-%'");
        return 'TRF-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $t @return array<string, mixed> */
    private function auditView(array $t): array
    {
        return array_intersect_key($t, array_flip(['transfer_no', 'from_account_id', 'to_account_id', 'transfer_date', 'amount', 'reference_no', 'purpose', 'gst_registration_id', 'business_activity_id', 'status']));
    }
}
