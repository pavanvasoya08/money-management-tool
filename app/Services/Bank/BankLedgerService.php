<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\Database;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Balances from the single bank ledger (bank_transactions).
 *
 *   balance = opening balance + posted credits − posted debits
 *
 * "Posted" means status posted or reversed: a reversed entry stays in the ledger and its
 * reversal (a new posted row in the opposite direction) nets it to zero. Drafts, pending,
 * approved-but-not-posted, rejected and cancelled entries never affect balances.
 * Integer paise arithmetic only (no floats).
 */
final class BankLedgerService
{
    public const EFFECTIVE = "('posted', 'reversed')";
    public const TYPE_LABELS = [
        'deposit' => 'Deposit', 'withdrawal' => 'Withdrawal', 'transfer_in' => 'Transfer in', 'transfer_out' => 'Transfer out',
        'bank_charges' => 'Bank charges', 'interest' => 'Interest received', 'adjustment' => 'Other adjustment', 'opening_adjustment' => 'Opening-balance adjustment', 'reversal' => 'Reversal',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** Posted balance, optionally as of a date (inclusive). */
    public function balance(int $accountId, ?string $asOf = null): int
    {
        $opening = Money::fromDb($this->db->scalar('SELECT opening_balance FROM bank_accounts WHERE id = ?', [$accountId]) ?? '0');
        $sql = "SELECT direction, amount FROM bank_transactions WHERE bank_account_id = ? AND status IN " . self::EFFECTIVE;
        $params = [$accountId];
        if ($asOf !== null) {
            $sql .= ' AND transaction_date <= ?';
            $params[] = $asOf;
        }
        $total = $opening;
        foreach ($this->db->select($sql, $params) as $r) {
            $total += $r['direction'] === 'credit' ? Money::fromDb($r['amount']) : -Money::fromDb($r['amount']);
        }
        return $total;
    }

    /** @param list<int> $ids @return array<int, int> id => posted balance */
    public function balances(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->select("SELECT id, opening_balance FROM bank_accounts WHERE id IN ({$in})", $ids) as $r) {
            $out[(int) $r['id']] = Money::fromDb($r['opening_balance']);
        }
        foreach ($this->db->select("SELECT bank_account_id, direction, amount FROM bank_transactions WHERE bank_account_id IN ({$in}) AND status IN " . self::EFFECTIVE, $ids) as $r) {
            $out[(int) $r['bank_account_id']] += $r['direction'] === 'credit' ? Money::fromDb($r['amount']) : -Money::fromDb($r['amount']);
        }
        return $out;
    }

    /**
     * Balance components. Reversal rows are counted against the type they reverse, so
     * e.g. "deposits" is net of reversed deposits.
     * @return array<string, int>
     */
    public function breakdown(int $accountId): array
    {
        $acc = $this->db->first('SELECT opening_balance FROM bank_accounts WHERE id = ?', [$accountId]);
        $out = ['opening' => Money::fromDb($acc['opening_balance'] ?? '0'), 'deposits' => 0, 'withdrawals' => 0, 'transfer_in' => 0, 'transfer_out' => 0,
            'bank_charges' => 0, 'interest' => 0, 'adjustments' => 0, 'opening_adjustments' => 0];
        foreach ($this->db->select(
            'SELECT t.direction, t.amount, COALESCE(o.type, t.type) AS base FROM bank_transactions t LEFT JOIN bank_transactions o ON o.id = t.reversal_of_id
             WHERE t.bank_account_id = ? AND t.status IN ' . self::EFFECTIVE, [$accountId]) as $r) {
            $signed = $r['direction'] === 'credit' ? Money::fromDb($r['amount']) : -Money::fromDb($r['amount']);
            match ($r['base']) {
                'deposit' => $out['deposits'] += $signed,
                'withdrawal' => $out['withdrawals'] -= $signed,
                'transfer_in' => $out['transfer_in'] += $signed,
                'transfer_out' => $out['transfer_out'] -= $signed,
                'bank_charges' => $out['bank_charges'] -= $signed,
                'interest' => $out['interest'] += $signed,
                'opening_adjustment' => $out['opening_adjustments'] += $signed,
                default => $out['adjustments'] += $signed,
            };
        }
        $out['balance'] = $out['opening'] + $out['deposits'] - $out['withdrawals'] + $out['transfer_in'] - $out['transfer_out'] - $out['bank_charges'] + $out['interest'] + $out['adjustments'] + $out['opening_adjustments'];
        return $out;
    }

    /**
     * Amount that may still be taken out: posted balance minus debits already waiting for
     * approval or posting, plus the overdraft limit when the account allows overdraft.
     */
    public function available(int $accountId, ?int $exceptTxn = null, ?int $exceptTransfer = null, bool $postedOnly = false): int
    {
        $acc = $this->db->first('SELECT allow_overdraft, overdraft_limit FROM bank_accounts WHERE id = ?', [$accountId]);
        $available = $this->balance($accountId) + ((int) ($acc['allow_overdraft'] ?? 0) === 1 ? Money::fromDb($acc['overdraft_limit']) : 0);
        if ($postedOnly) {
            return $available;
        }
        foreach ($this->db->select("SELECT id, amount FROM bank_transactions WHERE bank_account_id = ? AND direction = 'debit' AND status IN ('pending_approval', 'approved') AND transfer_id IS NULL", [$accountId]) as $r) {
            if ((int) $r['id'] !== $exceptTxn) {
                $available -= Money::fromDb($r['amount']);
            }
        }
        foreach ($this->db->select("SELECT id, amount FROM bank_transfers WHERE from_account_id = ? AND status IN ('pending_approval', 'approved')", [$accountId]) as $r) {
            if ((int) $r['id'] !== $exceptTransfer) {
                $available -= Money::fromDb($r['amount']);
            }
        }
        return $available;
    }

    /**
     * Serialise posting on these accounts for the rest of the current database transaction.
     * An UPDATE takes a row lock on MySQL/InnoDB (like SELECT … FOR UPDATE) and the write lock
     * on SQLite, so two postings cannot both pass the balance check. Ids are locked in
     * ascending order to avoid deadlocks.
     * @param list<int> $ids
     */
    public function lock(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        foreach ($ids as $id) {
            $this->db->execute('UPDATE bank_accounts SET lock_version = lock_version + 1 WHERE id = ?', [$id]);
        }
    }

    /**
     * Ledger for a period with the opening balance, each entry's balance before and after,
     * and the closing balance. Ordered by transaction date, then posting time.
     * @return array{opening: int, closing: int, rows: list<array<string, mixed>>, credits: int, debits: int}
     */
    public function ledger(int $accountId, ?string $from, ?string $to): array
    {
        $from = BusinessDate::isValid((string) $from) ? (string) $from : null;
        $to = BusinessDate::isValid((string) $to) ? (string) $to : null;
        $opening = $from !== null ? $this->balance($accountId, BusinessDate::addDays($from, -1)) : Money::fromDb($this->db->scalar('SELECT opening_balance FROM bank_accounts WHERE id = ?', [$accountId]) ?? '0');
        $sql = 'SELECT t.*, p.name AS partner_name, tr.transfer_no, o.transaction_no AS reverses_no, c.transaction_no AS capital_no
                FROM bank_transactions t LEFT JOIN partners p ON p.id = t.partner_id LEFT JOIN bank_transfers tr ON tr.id = t.transfer_id
                LEFT JOIN bank_transactions o ON o.id = t.reversal_of_id LEFT JOIN partner_capital_transactions c ON c.id = t.capital_transaction_id
                WHERE t.bank_account_id = ? AND t.status IN ' . self::EFFECTIVE;
        $params = [$accountId];
        if ($from !== null) {
            $sql .= ' AND t.transaction_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND t.transaction_date <= ?';
            $params[] = $to;
        }
        $rows = $this->db->select($sql . ' ORDER BY t.transaction_date, t.posted_at, t.id', $params);
        $running = $opening;
        $credits = 0;
        $debits = 0;
        foreach ($rows as &$r) {
            $amount = Money::fromDb($r['amount']);
            $r['balance_before'] = $running;
            if ($r['direction'] === 'credit') {
                $running += $amount;
                $credits += $amount;
            } else {
                $running -= $amount;
                $debits += $amount;
            }
            $r['balance_after'] = $running;
        }
        return ['opening' => $opening, 'closing' => $running, 'rows' => $rows, 'credits' => $credits, 'debits' => $debits];
    }

    /**
     * Money movement for accounts, excluding internal transfers (they are neither income nor
     * expense) and opening-balance adjustments (corrections). Reversals offset what they reverse.
     * @param list<int> $accountIds @return array{received: int, paid: int, transfers: int, charges: int, interest: int}
     */
    public function movement(array $accountIds, ?string $from = null, ?string $to = null): array
    {
        $out = ['received' => 0, 'paid' => 0, 'transfers' => 0, 'charges' => 0, 'interest' => 0];
        foreach ($this->movementRows($accountIds, $from, $to) as $r) {
            $amount = Money::fromDb($r['amount']);
            $sign = $r['is_reversal'] ? -1 : 1;
            match ($r['base']) {
                'deposit', 'interest' => $out['received'] += $sign * $amount,
                'withdrawal', 'bank_charges' => $out['paid'] += $sign * $amount,
                'adjustment' => $r['base_direction'] === 'credit' ? $out['received'] += $sign * $amount : $out['paid'] += $sign * $amount,
                'transfer_out' => $out['transfers'] += $sign * $amount,
                default => null,
            };
            if ($r['base'] === 'bank_charges') {
                $out['charges'] += $sign * $amount;
            } elseif ($r['base'] === 'interest') {
                $out['interest'] += $sign * $amount;
            }
        }
        return $out;
    }

    /** Monthly received / paid (transfers excluded) for a chart. @param list<int> $accountIds @return list<array{month: string, received: int, paid: int}> */
    public function monthly(array $accountIds, int $months = 6): array
    {
        $start = substr(BusinessDate::addMonths(substr(BusinessDate::today(), 0, 7) . '-01', -($months - 1)), 0, 10);
        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $buckets[substr(BusinessDate::addMonths($start, $i), 0, 7)] = ['received' => 0, 'paid' => 0];
        }
        foreach ($this->movementRows($accountIds, $start, null) as $r) {
            $m = substr((string) $r['transaction_date'], 0, 7);
            if (!isset($buckets[$m])) {
                continue;
            }
            $amount = ($r['is_reversal'] ? -1 : 1) * Money::fromDb($r['amount']);
            $credit = in_array($r['base'], ['deposit', 'interest'], true) || ($r['base'] === 'adjustment' && $r['base_direction'] === 'credit');
            $debit = in_array($r['base'], ['withdrawal', 'bank_charges'], true) || ($r['base'] === 'adjustment' && $r['base_direction'] === 'debit');
            if ($credit) {
                $buckets[$m]['received'] += $amount;
            } elseif ($debit) {
                $buckets[$m]['paid'] += $amount;
            }
        }
        $out = [];
        foreach ($buckets as $m => $b) {
            $out[] = ['month' => $m] + $b;
        }
        return $out;
    }

    /** @param list<int> $accountIds @return list<array<string, mixed>> */
    private function movementRows(array $accountIds, ?string $from, ?string $to): array
    {
        if ($accountIds === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $sql = "SELECT t.transaction_date, t.amount, COALESCE(o.type, t.type) AS base, COALESCE(o.direction, t.direction) AS base_direction, CASE WHEN t.reversal_of_id IS NULL THEN 0 ELSE 1 END AS is_reversal
                FROM bank_transactions t LEFT JOIN bank_transactions o ON o.id = t.reversal_of_id
                WHERE t.bank_account_id IN ({$in}) AND t.status IN " . self::EFFECTIVE;
        $params = $accountIds;
        if ($from !== null) {
            $sql .= ' AND t.transaction_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND t.transaction_date <= ?';
            $params[] = $to;
        }
        return $this->db->select($sql, $params);
    }
}
