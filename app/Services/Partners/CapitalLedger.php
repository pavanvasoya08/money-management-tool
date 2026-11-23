<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\Core\Database;

/**
 * Read model for partner capital. Every figure is derived from POSTED rows
 * of partner_capital_transactions — drafts, pending, approved-but-unposted,
 * rejected and cancelled rows never affect balances.
 *
 * Counted rows: status 'posted' (incl. reversal rows) and 'reversed' (the
 * original of a reversal pair, which keeps counting so the pair nets to 0).
 *
 * Closing = Opening + Contributions + Adjustments − Withdrawals − Reversed amounts
 *   opening      = signed opening_balance adjustments
 *   adjustments  = signed other adjustments
 *   reversed     = reversal debits − reversal credits
 * All maths in integer paise.
 */
final class CapitalLedger
{
    public const COUNTED = "('posted','reversed')";

    public function __construct(private readonly Database $db)
    {
    }

    /** Posted balance in paise, optionally as of a business date (inclusive). */
    public function balance(int $partnerId, ?string $asOf = null): int
    {
        $sql = 'SELECT SUM(CASE WHEN direction = \'credit\' THEN ROUND(amount * 100) ELSE -ROUND(amount * 100) END) FROM partner_capital_transactions
                WHERE partner_id = ? AND status IN ' . self::COUNTED;
        $params = [$partnerId];
        if ($asOf !== null) {
            $sql .= ' AND transaction_date <= ?';
            $params[] = $asOf;
        }
        return self::int($this->db->scalar($sql, $params));
    }

    /**
     * Per-partner breakdown for every partner matching $scope (DataAccess on partners, alias p).
     * @param array{0: string, 1: list<mixed>} $scope
     * @return array<int, array<string, int|string|null>> keyed by partner id
     */
    public function summaries(array $scope, ?string $asOf = null, bool $includeArchived = true): array
    {
        [$where, $params] = $scope;
        $date = $asOf !== null ? ' AND t.transaction_date <= ?' : '';
        $sql = "SELECT p.id AS partner_id,
            SUM(CASE WHEN t.status IN " . self::COUNTED . " AND t.type = 'contribution' THEN ROUND(t.amount * 100) ELSE 0 END) AS contributions,
            SUM(CASE WHEN t.status IN " . self::COUNTED . " AND t.type = 'withdrawal' THEN ROUND(t.amount * 100) ELSE 0 END) AS withdrawals,
            SUM(CASE WHEN t.status IN " . self::COUNTED . " AND t.type = 'adjustment' AND t.adjustment_category = 'opening_balance' THEN (CASE WHEN t.direction = 'credit' THEN 1 ELSE -1 END) * ROUND(t.amount * 100) ELSE 0 END) AS opening,
            SUM(CASE WHEN t.status IN " . self::COUNTED . " AND t.type = 'adjustment' AND t.adjustment_category <> 'opening_balance' THEN (CASE WHEN t.direction = 'credit' THEN 1 ELSE -1 END) * ROUND(t.amount * 100) ELSE 0 END) AS adjustments,
            SUM(CASE WHEN t.status = 'posted' AND t.type = 'reversal' THEN (CASE WHEN t.direction = 'debit' THEN 1 ELSE -1 END) * ROUND(t.amount * 100) ELSE 0 END) AS reversed,
            SUM(CASE WHEN t.status = 'posted' AND t.type = 'reversal' AND o.type = 'contribution' THEN ROUND(t.amount * 100) ELSE 0 END) AS reversed_contributions,
            SUM(CASE WHEN t.status = 'posted' AND t.type = 'reversal' AND o.type = 'withdrawal' THEN ROUND(t.amount * 100) ELSE 0 END) AS reversed_withdrawals,
            SUM(CASE WHEN t.status = 'pending_approval' AND t.type = 'contribution' THEN 1 ELSE 0 END) AS pending_contribution_count,
            SUM(CASE WHEN t.status = 'pending_approval' AND t.type = 'contribution' THEN ROUND(t.amount * 100) ELSE 0 END) AS pending_contributions,
            SUM(CASE WHEN t.status = 'approved' AND t.type = 'contribution' THEN ROUND(t.amount * 100) ELSE 0 END) AS unposted_contributions,
            SUM(CASE WHEN t.status = 'pending_approval' AND t.type = 'withdrawal' THEN 1 ELSE 0 END) AS pending_withdrawal_count,
            SUM(CASE WHEN t.status = 'pending_approval' AND t.type = 'withdrawal' THEN ROUND(t.amount * 100) ELSE 0 END) AS pending_withdrawals,
            SUM(CASE WHEN t.status = 'approved' AND t.type = 'withdrawal' THEN ROUND(t.amount * 100) ELSE 0 END) AS unposted_withdrawals,
            MAX(CASE WHEN t.status = 'posted' AND t.type = 'contribution' THEN t.transaction_date END) AS last_contribution_date,
            MAX(CASE WHEN t.status = 'posted' AND t.type = 'withdrawal' THEN t.transaction_date END) AS last_withdrawal_date
            FROM partners p
            LEFT JOIN partner_capital_transactions t ON t.partner_id = p.id{$date}
            LEFT JOIN partner_capital_transactions o ON o.id = t.reversal_of_id
            WHERE {$where}" . ($includeArchived ? '' : " AND p.status <> 'archived'") . '
            GROUP BY p.id';
        $out = [];
        foreach ($this->db->select($sql, $asOf !== null ? array_merge([$asOf], $params) : $params) as $r) {
            $row = [];
            foreach ($r as $k => $v) {
                $row[$k] = in_array($k, ['last_contribution_date', 'last_withdrawal_date'], true) ? ($v !== null ? (string) $v : null) : self::int($v);
            }
            $row['closing'] = $row['opening'] + $row['contributions'] + $row['adjustments'] - $row['withdrawals'] - $row['reversed'];
            $row['net_contributed'] = $row['contributions'] - $row['reversed_contributions'];
            $row['net_withdrawn'] = $row['withdrawals'] - $row['reversed_withdrawals'];
            $out[(int) $r['partner_id']] = $row;
        }
        return $out;
    }

    /** @return array<string, int|string|null> */
    public function summary(int $partnerId): array
    {
        return $this->summaries(['p.id = ?', [$partnerId]])[$partnerId] ?? [];
    }

    /**
     * Ledger lines with running balance.
     * @param array{0: string, 1: list<mixed>}|null $scope optional extra restriction on t (capital_ledger record scope)
     * @return array{opening: int, closing: int, debit: int, credit: int, rows: list<array<string, mixed>>}
     */
    public function entries(int $partnerId, ?string $from, ?string $to): array
    {
        $opening = $from !== null ? $this->balance($partnerId, \App\Support\BusinessDate::addDays($from, -1)) : 0;
        $sql = 'SELECT t.*, o.transaction_no AS reversal_of_no, o.type AS reversal_of_type FROM partner_capital_transactions t
                LEFT JOIN partner_capital_transactions o ON o.id = t.reversal_of_id
                WHERE t.partner_id = ? AND t.status IN ' . self::COUNTED;
        $params = [$partnerId];
        if ($from !== null) {
            $sql .= ' AND t.transaction_date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND t.transaction_date <= ?';
            $params[] = $to;
        }
        $sql .= ' ORDER BY t.transaction_date, t.posted_at, t.id';
        $running = $opening;
        $debit = $credit = 0;
        $rows = [];
        foreach ($this->db->select($sql, $params) as $r) {
            $amt = \App\Support\Money::fromDb($r['amount']);
            if ($r['direction'] === 'credit') {
                $credit += $amt;
                $running += $amt;
                $r['credit'] = $amt;
                $r['debit'] = 0;
            } else {
                $debit += $amt;
                $running -= $amt;
                $r['debit'] = $amt;
                $r['credit'] = 0;
            }
            $r['running_balance'] = $running;
            $rows[] = $r;
        }
        return ['opening' => $opening, 'closing' => $running, 'debit' => $debit, 'credit' => $credit, 'rows' => $rows];
    }

    /** Amount already committed to debits that are not yet posted (pending approval or approved). */
    public function committedDebits(int $partnerId, ?int $exceptId = null): int
    {
        $sql = "SELECT SUM(ROUND(amount * 100)) FROM partner_capital_transactions WHERE partner_id = ? AND direction = 'debit' AND status IN ('pending_approval','approved')";
        $params = [$partnerId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $params[] = $exceptId;
        }
        return self::int($this->db->scalar($sql, $params));
    }

    /** Eligible available capital = posted balance − debits already awaiting approval/posting. */
    public function available(int $partnerId, ?int $exceptId = null): int
    {
        return $this->balance($partnerId) - $this->committedDebits($partnerId, $exceptId);
    }

    /** SUM() results arrive as int, float or numeric string depending on the driver; values are whole paise. */
    public static function int(mixed $v): int
    {
        if ($v === null || $v === '') {
            return 0;
        }
        return (int) round((float) $v);
    }
}
