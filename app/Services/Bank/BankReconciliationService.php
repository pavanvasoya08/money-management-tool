<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Bank reconciliation: compare imported statement lines with posted ledger entries for a
 * period, match them one-to-one (manually or by accepting suggestions), ignore lines with a
 * reason, and complete with a balance snapshot. Reconciliation never edits or creates ledger
 * entries; a line missing from the ledger can be turned into a *draft* entry that follows the
 * normal approval process. Completed reconciliations are reopened only through approval.
 */
final class BankReconciliationService
{
    public const STATUSES = ['in_progress' => 'In progress', 'completed' => 'Completed', 'reopen_pending' => 'Reopening requested'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT r.*, a.account_code, a.display_name AS account_name, a.is_archived AS account_archived, a.status AS account_status, u.name AS reconciled_by_name, s.original_name AS statement_name
            FROM bank_reconciliations r JOIN bank_accounts a ON a.id = r.bank_account_id LEFT JOIN users u ON u.id = r.reconciled_by LEFT JOIN bank_statements s ON s.id = r.statement_id WHERE r.id = ?', [$id]);
    }

    /** @param array{0: string, 1: list<mixed>} $scope alias a @return list<array<string, mixed>> */
    public function list(array $scope, ?int $accountId = null): array
    {
        [$w, $p] = $scope;
        if ($accountId !== null) {
            $w .= ' AND r.bank_account_id = ?';
            $p[] = $accountId;
        }
        return $this->db->select("SELECT r.*, a.account_code, a.display_name AS account_name, u.name AS reconciled_by_name,
                (SELECT COUNT(*) FROM bank_reconciliation_matches m WHERE m.reconciliation_id = r.id AND m.status = 'active') AS matches
            FROM bank_reconciliations r JOIN bank_accounts a ON a.id = r.bank_account_id LEFT JOIN users u ON u.id = r.reconciled_by WHERE {$w} ORDER BY r.period_to DESC, r.id DESC LIMIT 500", $p);
    }

    /** Last completed reconciliation date per account. @param list<int> $ids @return array<int, string> */
    public function lastCompleted(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->select("SELECT bank_account_id, MAX(period_to) AS last_to FROM bank_reconciliations WHERE status = 'completed' AND bank_account_id IN ({$in}) GROUP BY bank_account_id", $ids) as $r) {
            $out[(int) $r['bank_account_id']] = (string) $r['last_to'];
        }
        return $out;
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function start(array $acc, array $data, array $actor): int
    {
        if ((int) $acc['is_archived'] === 1 || in_array($acc['status'], ['draft', 'pending_approval'], true)) {
            throw new DomainRuleException('This account cannot be reconciled.');
        }
        $data['statement_closing_balance'] = str_replace(',', '', trim((string) ($data['statement_closing_balance'] ?? '')));
        $v = Validator::make($data, ['period_from' => 'required|date', 'period_to' => 'required|date|before_or_equal:today', 'statement_id' => 'nullable|integer',
            'statement_closing_balance' => 'nullable|regex:/^-?\d{1,13}(\.\d{1,2})?$/', 'notes' => 'nullable|string|max:2000'], ['period_from' => 'From', 'period_to' => 'To']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $c = $v->validated();
        if ($c['period_to'] < $c['period_from']) {
            throw ValidationException::withMessage('period_to', 'The end date must be on or after the start date.');
        }
        if ($this->db->scalar("SELECT 1 FROM bank_reconciliations WHERE bank_account_id = ? AND status <> 'completed'", [$acc['id']])) {
            throw new DomainRuleException('A reconciliation for this account is already open. Complete it first.');
        }
        $overlap = $this->db->scalar("SELECT reconciliation_no FROM bank_reconciliations WHERE bank_account_id = ? AND status = 'completed' AND period_from <= ? AND period_to >= ?", [$acc['id'], $c['period_to'], $c['period_from']]);
        if ($overlap) {
            throw ValidationException::withMessage('period_from', "The period overlaps completed reconciliation {$overlap}. Reopen it instead.");
        }
        $statementId = (int) ($c['statement_id'] ?? 0) ?: null;
        $closing = ($c['statement_closing_balance'] ?? '') !== '' ? Money::toDb(Money::parseSigned((string) $c['statement_closing_balance'])) : null;
        if ($statementId !== null) {
            $st = $this->db->first('SELECT * FROM bank_statements WHERE id = ? AND bank_account_id = ?', [$statementId, $acc['id']]) ?? throw ValidationException::withMessage('statement_id', 'Choose a statement of this account.');
            $closing ??= $st['closing_balance'];
        }
        $no = 'REC-' . str_pad((string) ((int) $this->db->scalar("SELECT COALESCE(MAX(CAST(SUBSTR(reconciliation_no, 5) AS UNSIGNED)), 0) FROM bank_reconciliations WHERE reconciliation_no LIKE 'REC-%'") + 1), 6, '0', STR_PAD_LEFT);
        $id = $this->db->insert('bank_reconciliations', ['reconciliation_no' => $no, 'bank_account_id' => (int) $acc['id'], 'statement_id' => $statementId, 'period_from' => $c['period_from'], 'period_to' => $c['period_to'],
            'statement_closing_balance' => $closing, 'status' => 'in_progress', 'notes' => ($c['notes'] ?? '') ?: null, 'created_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        App::activity()->log('bank.reconciliation_started', "Started reconciliation {$no} for {$acc['account_code']} ({$c['period_from']} – {$c['period_to']})", 'bank_account', (int) $acc['id'], module: 'bank_accounts',
            newValues: ['reconciliation_id' => $id, 'period_from' => $c['period_from'], 'period_to' => $c['period_to'], 'statement_id' => $statementId]);
        return $id;
    }

    /** Imported, valid statement lines of the period. @param array<string, mixed> $rec @return list<array<string, mixed>> */
    public function statementRows(array $rec): array
    {
        return $this->db->select("SELECT r.*, m.id AS match_id, m.bank_transaction_id, m.match_type, t.transaction_no AS matched_no
            FROM bank_statement_rows r JOIN bank_statement_imports i ON i.id = r.import_id JOIN bank_statements s ON s.id = r.statement_id
            LEFT JOIN bank_reconciliation_matches m ON m.statement_row_id = r.id AND m.status = 'active' LEFT JOIN bank_transactions t ON t.id = m.bank_transaction_id
            WHERE r.bank_account_id = ? AND i.status = 'imported' AND r.status = 'valid' AND s.status = 'active' AND r.txn_date BETWEEN ? AND ? ORDER BY r.txn_date, r.row_no",
            [$rec['bank_account_id'], $rec['period_from'], $rec['period_to']]);
    }

    /** Posted ledger entries of the period with their match. @param array<string, mixed> $rec @return list<array<string, mixed>> */
    public function ledgerRows(array $rec): array
    {
        return $this->db->select('SELECT t.*, m.id AS match_id, m.statement_row_id, m.reconciliation_id AS matched_in FROM bank_transactions t
            LEFT JOIN bank_reconciliation_matches m ON m.bank_transaction_id = t.id AND m.status = \'active\'
            WHERE t.bank_account_id = ? AND t.status IN ' . BankLedgerService::EFFECTIVE . ' AND t.transaction_date BETWEEN ? AND ? ORDER BY t.transaction_date, t.id',
            [$rec['bank_account_id'], $rec['period_from'], $rec['period_to']]);
    }

    /**
     * Suggested one-to-one pairs: same direction and amount, dates within the tolerance;
     * a matching reference ranks first, then the closest date.
     * @param array<string, mixed> $rec @return list<array{row: array<string, mixed>, txn: array<string, mixed>, score: int, reason: string}>
     */
    public function suggestions(array $rec): array
    {
        $tolerance = (int) App::settings()->get('bank_match_tolerance_days', 3);
        $rows = array_values(array_filter($this->statementRows($rec), static fn (array $r): bool => $r['match_status'] === 'unmatched'));
        $txns = array_values(array_filter($this->ledgerRows($rec), static fn (array $t): bool => $t['match_id'] === null));
        $candidates = [];
        foreach ($rows as $r) {
            $dir = Money::fromDb($r['debit']) > 0 ? 'debit' : 'credit';
            $amount = $dir === 'debit' ? Money::fromDb($r['debit']) : Money::fromDb($r['credit']);
            foreach ($txns as $t) {
                if ($t['direction'] !== $dir || Money::fromDb($t['amount']) !== $amount) {
                    continue;
                }
                $days = abs((int) ((strtotime((string) $t['transaction_date']) - strtotime((string) $r['txn_date'])) / 86400));
                if ($days > $tolerance) {
                    continue;
                }
                $refMatch = self::refMatches($r, $t);
                $candidates[] = ['row' => $r, 'txn' => $t, 'score' => ($refMatch ? 1000 : 0) + (100 - $days),
                    'reason' => 'Same amount' . ($refMatch ? ', same reference' : '') . ($days === 0 ? ', same date' : ", {$days} day(s) apart")];
            }
        }
        usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        $usedRows = [];
        $usedTxns = [];
        $out = [];
        foreach ($candidates as $c) {
            if (isset($usedRows[$c['row']['id']]) || isset($usedTxns[$c['txn']['id']])) {
                continue;
            }
            $usedRows[$c['row']['id']] = true;
            $usedTxns[$c['txn']['id']] = true;
            $out[] = $c;
        }
        return $out;
    }

    /** @param array<string, mixed> $rec @param array<string, mixed> $actor */
    public function match(array $rec, int $rowId, int $txnId, array $actor, string $type = 'manual'): int
    {
        $this->assertOpen($rec);
        return $this->db->transaction(function (Database $db) use ($rec, $rowId, $txnId, $actor, $type): int {
            $row = $db->first("SELECT r.* FROM bank_statement_rows r JOIN bank_statement_imports i ON i.id = r.import_id WHERE r.id = ? AND r.bank_account_id = ? AND i.status = 'imported' AND r.status = 'valid'", [$rowId, $rec['bank_account_id']])
                ?? throw new DomainRuleException('Statement line not found for this account.');
            $txn = $db->first('SELECT * FROM bank_transactions WHERE id = ? AND bank_account_id = ? AND status IN ' . BankLedgerService::EFFECTIVE, [$txnId, $rec['bank_account_id']])
                ?? throw new DomainRuleException('Only posted entries of this account can be matched.');
            if ($row['match_status'] !== 'unmatched') {
                throw new DomainRuleException('This statement line is already ' . $row['match_status'] . '.');
            }
            $dir = Money::fromDb($row['debit']) > 0 ? 'debit' : 'credit';
            $amount = $dir === 'debit' ? Money::fromDb($row['debit']) : Money::fromDb($row['credit']);
            if ($txn['direction'] !== $dir || Money::fromDb($txn['amount']) !== $amount) {
                throw new DomainRuleException('The statement line (' . ($dir === 'debit' ? 'debit ' : 'credit ') . Money::format($amount) . ') and the entry (' . $txn['direction'] . ' ' . Money::format(Money::fromDb($txn['amount'])) . ') must have the same direction and amount.');
            }
            try {
                $id = $db->insert('bank_reconciliation_matches', ['reconciliation_id' => (int) $rec['id'], 'statement_row_id' => $rowId, 'bank_transaction_id' => $txnId, 'match_type' => $type === 'suggested' ? 'suggested' : 'manual',
                    'status' => 'active', 'active_row_key' => $rowId, 'active_txn_key' => $txnId, 'matched_by' => (int) $actor['id'], 'matched_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            } catch (\PDOException) {
                throw new DomainRuleException('This entry is already matched to another statement line.');
            }
            $db->update('bank_statement_rows', ['match_status' => 'matched', 'updated_at' => now()], ['id' => $rowId]);
            App::activity()->log('bank.reconciliation_matched', "Matched statement line #{$rowId} with {$txn['transaction_no']} in {$rec['reconciliation_no']}", 'bank_account', (int) $rec['bank_account_id'], module: 'bank_accounts',
                newValues: ['reconciliation_id' => (int) $rec['id'], 'statement_row_id' => $rowId, 'transaction_id' => $txnId, 'match_type' => $type]);
            return $id;
        });
    }

    /** Accept the listed suggestions (row:txn pairs). @param array<string, mixed> $rec @param list<string> $pairs @param array<string, mixed> $actor */
    public function acceptSuggestions(array $rec, array $pairs, array $actor): int
    {
        $n = 0;
        foreach ($pairs as $pair) {
            [$row, $txn] = array_map('intval', explode(':', (string) $pair) + [0, 0]);
            if ($row > 0 && $txn > 0) {
                $this->match($rec, $row, $txn, $actor, 'suggested');
                $n++;
            }
        }
        return $n;
    }

    /** @param array<string, mixed> $rec @param array<string, mixed> $actor */
    public function unmatch(array $rec, int $matchId, string $reason, array $actor): void
    {
        $this->assertOpen($rec);
        $m = $this->db->first("SELECT * FROM bank_reconciliation_matches WHERE id = ? AND reconciliation_id = ? AND status = 'active'", [$matchId, $rec['id']]) ?? throw new DomainRuleException('Match not found.');
        $this->db->transaction(function (Database $db) use ($m, $reason, $actor): void {
            $db->update('bank_reconciliation_matches', ['status' => 'removed', 'active_row_key' => null, 'active_txn_key' => null, 'removed_by' => (int) $actor['id'], 'removed_at' => now(), 'removed_reason' => mb_substr(trim($reason) ?: 'Unmatched', 0, 255), 'updated_at' => now()], ['id' => $m['id']]);
            $db->update('bank_statement_rows', ['match_status' => 'unmatched', 'updated_at' => now()], ['id' => $m['statement_row_id']]);
        });
        App::activity()->log('bank.reconciliation_unmatched', "Removed match #{$matchId} in {$rec['reconciliation_no']}", 'bank_account', (int) $rec['bank_account_id'], module: 'bank_accounts', newValues: ['reconciliation_id' => (int) $rec['id'], 'match_id' => $matchId, 'reason' => $reason]);
    }

    /** Mark a statement line as not needing a ledger entry (e.g. already covered elsewhere), with a reason. @param array<string, mixed> $rec @param array<string, mixed> $actor */
    public function ignoreRow(array $rec, int $rowId, ?string $reason, array $actor, bool $ignore = true): void
    {
        $this->assertOpen($rec);
        $row = $this->db->first('SELECT * FROM bank_statement_rows WHERE id = ? AND bank_account_id = ?', [$rowId, $rec['bank_account_id']]) ?? throw new DomainRuleException('Statement line not found.');
        if ($ignore && $row['match_status'] !== 'unmatched') {
            throw new DomainRuleException('Only unmatched lines can be ignored.');
        }
        if ($ignore && trim((string) $reason) === '') {
            throw ValidationException::withMessage('reason', 'Say why this line needs no ledger entry.');
        }
        if (!$ignore && $row['match_status'] !== 'ignored') {
            return;
        }
        $this->db->update('bank_statement_rows', ['match_status' => $ignore ? 'ignored' : 'unmatched', 'ignore_reason' => $ignore ? mb_substr((string) $reason, 0, 255) : null, 'updated_at' => now()], ['id' => $rowId]);
        App::activity()->log($ignore ? 'bank.reconciliation_line_ignored' : 'bank.reconciliation_line_restored', ($ignore ? 'Ignored' : 'Restored') . " statement line #{$rowId} in {$rec['reconciliation_no']}", 'bank_account', (int) $rec['bank_account_id'],
            module: 'bank_accounts', newValues: ['reconciliation_id' => (int) $rec['id'], 'statement_row_id' => $rowId, 'reason' => $reason]);
    }

    /**
     * Summary used on screen and when completing.
     * @param array<string, mixed> $rec
     * @return array{rows: int, matched: int, ignored: int, unmatched_rows: int, unmatched_ledger: int, ledger_closing: int, statement_closing: ?int, difference: ?int, duplicates: list<array<string, mixed>>}
     */
    public function summary(array $rec): array
    {
        $rows = $this->statementRows($rec);
        $ledger = $this->ledgerRows($rec);
        $closing = (new BankLedgerService($this->db))->balance((int) $rec['bank_account_id'], (string) $rec['period_to']);
        $stmt = $rec['statement_closing_balance'] !== null ? Money::fromDb($rec['statement_closing_balance']) : null;
        $dups = $this->db->select('SELECT t.direction, t.amount, t.transaction_date, COALESCE(t.reference_no, \'\') AS ref, COUNT(*) AS n, MIN(t.transaction_no) AS first_no, MAX(t.transaction_no) AS last_no
            FROM bank_transactions t WHERE t.bank_account_id = ? AND t.status = \'posted\' AND t.reversal_of_id IS NULL AND t.transaction_date BETWEEN ? AND ?
            GROUP BY t.direction, t.amount, t.transaction_date, COALESCE(t.reference_no, \'\') HAVING COUNT(*) > 1', [$rec['bank_account_id'], $rec['period_from'], $rec['period_to']]);
        return [
            'rows' => count($rows), 'matched' => count(array_filter($rows, static fn (array $r): bool => $r['match_status'] === 'matched')),
            'ignored' => count(array_filter($rows, static fn (array $r): bool => $r['match_status'] === 'ignored')),
            'unmatched_rows' => count(array_filter($rows, static fn (array $r): bool => $r['match_status'] === 'unmatched')),
            'unmatched_ledger' => count(array_filter($ledger, static fn (array $t): bool => $t['match_id'] === null)),
            'ledger_closing' => $closing, 'statement_closing' => $stmt, 'difference' => $stmt === null ? null : $stmt - $closing, 'duplicates' => $dups,
        ];
    }

    /** @param array<string, mixed> $rec @param array<string, mixed> $actor */
    public function complete(array $rec, ?string $notes, array $actor): void
    {
        $this->assertOpen($rec);
        $s = $this->summary($rec);
        if ($s['unmatched_rows'] > 0) {
            throw new DomainRuleException("{$s['unmatched_rows']} statement line(s) are still unmatched. Match them, record the missing entries, or ignore them with a reason.");
        }
        if ($s['difference'] !== null && $s['difference'] !== 0 && trim((string) $notes) === '') {
            throw ValidationException::withMessage('notes', 'The statement closing balance differs from the ledger by ' . Money::format($s['difference']) . '. Explain the difference in the notes.');
        }
        $changed = $this->db->execute("UPDATE bank_reconciliations SET status = 'completed', ledger_closing_balance = ?, reconciled_at = ?, reconciled_by = ?, notes = COALESCE(?, notes), updated_at = ? WHERE id = ? AND status = 'in_progress'",
            [Money::toDb($s['ledger_closing']), now(), (int) $actor['id'], trim((string) $notes) ?: null, now(), (int) $rec['id']]);
        if ($changed !== 1) {
            throw new DomainRuleException('This reconciliation was changed by someone else.');
        }
        App::activity()->log('bank.reconciliation_completed', "Completed reconciliation {$rec['reconciliation_no']} for {$rec['account_code']}", 'bank_account', (int) $rec['bank_account_id'], module: 'bank_accounts',
            newValues: ['reconciliation_id' => (int) $rec['id'], 'matched' => $s['matched'], 'ignored' => $s['ignored'], 'outstanding_ledger' => $s['unmatched_ledger'], 'ledger_closing' => Money::toDb($s['ledger_closing']),
                'difference' => $s['difference'] === null ? null : Money::toDb($s['difference'])]);
    }

    /** Ask to reopen a completed reconciliation (approval workflow). @param array<string, mixed> $rec @param array<string, mixed> $actor */
    public function requestReopen(array $rec, string $reason, array $actor): array
    {
        if ($rec['status'] !== 'completed') {
            throw new DomainRuleException('Only completed reconciliations can be reopened.');
        }
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessage('reason', 'Give a reason (at least 5 characters).');
        }
        $acc = (new BankAccountService($this->db))->find((int) $rec['bank_account_id']);
        $this->db->update('bank_reconciliations', ['status' => 'reopen_pending', 'updated_at' => now()], ['id' => $rec['id']]);
        try {
            return (new BankAccountService($this->db))->request($acc, 'reconciliation_reopen', ['status' => 'completed', 'reconciliation' => $rec['reconciliation_no']], ['status' => 'in_progress'], $reason, $actor, 'bank_accounts.reopen_reconciliation', (int) $rec['id']);
        } catch (\Throwable $e) {
            $this->db->update('bank_reconciliations', ['status' => 'completed'], ['id' => $rec['id']]);
            throw $e;
        }
    }

    /** Called by BankAccountService when the reopen request is decided. @param array<string, mixed> $change @param array<string, mixed> $by */
    public function decideReopen(array $change, string $status, array $by): void
    {
        $rec = $this->find((int) $change['reconciliation_id']);
        if ($rec === null || $rec['status'] !== 'reopen_pending') {
            return;
        }
        if ($status === 'approved') {
            $this->db->execute("UPDATE bank_reconciliations SET status = 'in_progress', times_reopened = times_reopened + 1, reconciled_at = NULL, reconciled_by = NULL, updated_at = ? WHERE id = ?", [now(), $rec['id']]);
        } else {
            $this->db->update('bank_reconciliations', ['status' => 'completed', 'updated_at' => now()], ['id' => $rec['id']]);
        }
        App::activity()->log('bank.reconciliation_' . ($status === 'approved' ? 'reopened' : 'reopen_' . $status), ($status === 'approved' ? 'Reopened' : ucfirst($status) . ' reopening of') . " reconciliation {$rec['reconciliation_no']}", 'bank_account', (int) $rec['bank_account_id'],
            module: 'bank_accounts', newValues: ['reconciliation_id' => (int) $rec['id'], 'reason' => $change['reason']], userId: $by['id']);
    }

    /** @param array<string, mixed> $rec */
    private function assertOpen(array $rec): void
    {
        if ($rec['status'] !== 'in_progress') {
            throw new DomainRuleException($rec['status'] === 'completed' ? 'This reconciliation is completed. Request reopening to change it.' : 'Reopening of this reconciliation is waiting for approval.');
        }
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $txn */
    public static function refMatches(array $row, array $txn): bool
    {
        $ref = mb_strtolower(trim((string) $row['reference_no']));
        if ($ref === '') {
            return false;
        }
        foreach (['reference_no', 'utr_cheque_no'] as $k) {
            $v = mb_strtolower(trim((string) ($txn[$k] ?? '')));
            if ($v !== '' && (str_contains($ref, $v) || str_contains($v, $ref))) {
                return true;
            }
        }
        return false;
    }
}
