<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Figures for the Bank Management dashboard, all from database records within the user's
 * record scope. Money received / paid excludes internal transfers and opening-balance
 * adjustments; totals are INR accounts only.
 */
final class BankDashboardService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * @param array<string, mixed> $f bank, type, status, partner, gst, activity, from, to
     * @param array{0: string, 1: list<mixed>} $scope alias a
     * @return array<string, mixed>
     */
    public function data(array $f, array $scope): array
    {
        $accSvc = new BankAccountService($this->db);
        $ledger = new BankLedgerService($this->db);
        [$w, $p] = $accSvc->filterSql($f, $scope);
        $accounts = $this->db->select("SELECT a.*, t.name AS type_name FROM bank_accounts a JOIN bank_account_types t ON t.id = a.account_type_id WHERE {$w} ORDER BY a.is_central_funding DESC, a.display_name", $p);
        $ids = array_map(static fn (array $a): int => (int) $a['id'], $accounts);
        $inr = array_values(array_map(static fn (array $a): int => (int) $a['id'], array_filter($accounts, static fn (array $a): bool => $a['currency'] === 'INR')));
        $balances = $ledger->balances($ids);
        $counts = array_fill_keys(array_keys(BankAccountService::STATUSES), 0);
        $opening = 0;
        $current = 0;
        foreach ($accounts as &$a) {
            $counts[$a['status']]++;
            $a['balance_paise'] = $balances[(int) $a['id']] ?? 0;
            if ($a['currency'] === 'INR') {
                $opening += Money::fromDb($a['opening_balance']);
                $current += $a['balance_paise'];
            }
        }
        unset($a);
        $from = BusinessDate::isValid((string) ($f['from'] ?? '')) ? (string) $f['from'] : null;
        $to = BusinessDate::isValid((string) ($f['to'] ?? '')) ? (string) $f['to'] : null;
        $movement = $ledger->movement($inr, $from, $to);
        // Reconciliation pending: active accounts whose last completed reconciliation is older than the setting (or none).
        $last = (new BankReconciliationService($this->db))->lastCompleted($ids);
        $cutoff = BusinessDate::addDays(BusinessDate::today(), -(int) App::settings()->get('bank_reconciliation_overdue_days', 30));
        $pendingRec = [];
        foreach ($accounts as $a) {
            if ($a['status'] === 'active' && (!isset($last[(int) $a['id']]) || $last[(int) $a['id']] < $cutoff)) {
                $pendingRec[] = $a + ['last_reconciled' => $last[(int) $a['id']] ?? null];
            }
        }
        $in = $ids === [] ? '0' : implode(',', $ids);
        $dateSql = ($from !== null ? " AND t.transaction_date >= '" . $from . "'" : '') . ($to !== null ? " AND t.transaction_date <= '" . $to . "'" : '');
        $recentTxns = $this->db->select("SELECT t.*, a.display_name AS account_name, a.account_code FROM bank_transactions t JOIN bank_accounts a ON a.id = t.bank_account_id
            WHERE t.bank_account_id IN ({$in}) AND t.transfer_id IS NULL{$dateSql} ORDER BY t.created_at DESC, t.id DESC LIMIT 8");
        $trDate = str_replace('t.transaction_date', 'tr.transfer_date', $dateSql);
        $recentTransfers = $this->db->select("SELECT tr.*, fa.display_name AS from_name, ta.display_name AS to_name FROM bank_transfers tr JOIN bank_accounts fa ON fa.id = tr.from_account_id JOIN bank_accounts ta ON ta.id = tr.to_account_id
            WHERE (tr.from_account_id IN ({$in}) OR tr.to_account_id IN ({$in})){$trDate} ORDER BY tr.created_at DESC, tr.id DESC LIMIT 6");
        $open = (int) $this->db->scalar("SELECT COUNT(*) FROM bank_transactions t WHERE t.bank_account_id IN ({$in}) AND t.status IN ('pending_approval', 'approved')")
            + (int) $this->db->scalar("SELECT COUNT(*) FROM bank_transfers tr WHERE (tr.from_account_id IN ({$in}) OR tr.to_account_id IN ({$in})) AND tr.status IN ('pending_approval', 'approved')");
        $chartAccounts = array_values(array_filter($accounts, static fn (array $a): bool => $a['currency'] === 'INR' && !in_array($a['status'], ['draft', 'pending_approval', 'closed'], true)));
        $monthly = $ledger->monthly($inr, 6);
        return [
            'accounts' => $accounts, 'counts' => $counts, 'total' => count($accounts), 'opening' => $opening, 'current' => $current, 'movement' => $movement,
            'pendingRec' => $pendingRec, 'recentTxns' => $recentTxns, 'recentTransfers' => $recentTransfers, 'awaiting' => $open,
            'balanceChart' => ['labels' => array_map(static fn (array $a): string => $a['display_name'], $chartAccounts), 'values' => array_map(static fn (array $a): float => Money::toFloat($a['balance_paise']), $chartAccounts)],
            'monthlyChart' => ['labels' => array_map(static fn (array $m): string => date('M Y', (int) strtotime($m['month'] . '-01')), $monthly),
                'received' => array_map(static fn (array $m): float => Money::toFloat($m['received']), $monthly), 'paid' => array_map(static fn (array $m): float => Money::toFloat($m['paid']), $monthly)],
            'monthly' => $monthly,
            'central' => $this->central($ids),
        ];
    }

    /**
     * Central joint funding account panel: partner contributions received (Phase 3 links),
     * money moved to business accounts, available balance and recent movements.
     * @param list<int> $visibleIds @return array<string, mixed>|null
     */
    public function central(array $visibleIds): ?array
    {
        $acc = (new BankAccountService($this->db))->central();
        if ($acc === null || !in_array((int) $acc['id'], $visibleIds, true)) {
            return null;
        }
        $id = (int) $acc['id'];
        $sum = function (string $sql) use ($id): int {
            $total = 0;
            foreach ($this->db->select($sql, [$id]) as $r) {
                $total += ($r['sign'] ?? 1) * Money::fromDb($r['amount']);
            }
            return $total;
        };
        $effective = BankLedgerService::EFFECTIVE;
        $contributions = $sum("SELECT t.amount, CASE WHEN t.status = 'reversed' THEN 0 ELSE 1 END AS sign FROM bank_transactions t WHERE t.bank_account_id = ? AND t.capital_transaction_id IS NOT NULL AND t.direction = 'credit' AND t.status IN {$effective}");
        $out = $sum("SELECT t.amount, CASE WHEN t.status = 'reversed' THEN 0 ELSE 1 END AS sign FROM bank_transactions t WHERE t.bank_account_id = ? AND t.type = 'transfer_out' AND t.status IN {$effective}");
        $in = $sum("SELECT t.amount, CASE WHEN t.status = 'reversed' THEN 0 ELSE 1 END AS sign FROM bank_transactions t WHERE t.bank_account_id = ? AND t.type = 'transfer_in' AND t.status IN {$effective}");
        $ledger = new BankLedgerService($this->db);
        return [
            'account' => $acc, 'contributions' => $contributions, 'transferred_out' => $out, 'transferred_in' => $in,
            'balance' => $ledger->balance($id), 'available' => $ledger->available($id),
            'recentContributions' => $this->db->select("SELECT t.id, t.transaction_no, t.transaction_date, t.amount, t.status, c.transaction_no AS capital_no, c.id AS capital_id, p.name AS partner_name
                FROM bank_transactions t JOIN partner_capital_transactions c ON c.id = t.capital_transaction_id LEFT JOIN partners p ON p.id = t.partner_id
                WHERE t.bank_account_id = ? AND t.direction = 'credit' ORDER BY t.transaction_date DESC, t.id DESC LIMIT 5", [$id]),
        ];
    }
}
