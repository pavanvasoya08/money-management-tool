<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;
use App\Support\Money;

/**
 * Bank statements: upload (CSV, PDF or Excel file kept privately), statement period and
 * printed balances, and CSV import with a column mapping → preview → import.
 *
 * Importing stores statement lines for reconciliation only. It never creates, changes or
 * posts ledger entries; lines already imported from another statement of the same account
 * are flagged as duplicates and skipped. There is no bank API or automatic synchronisation.
 */
final class BankStatementService
{
    public const MAX_ROWS = 5000;
    public const DATE_FORMATS = ['d/m/Y' => 'DD/MM/YYYY', 'd-m-Y' => 'DD-MM-YYYY', 'd.m.Y' => 'DD.MM.YYYY', 'Y-m-d' => 'YYYY-MM-DD', 'm/d/Y' => 'MM/DD/YYYY', 'd/m/y' => 'DD/MM/YY', 'd-M-Y' => 'DD-Mon-YYYY', 'd M Y' => 'DD Mon YYYY'];
    /** Fields a CSV column can be mapped to. amount = one signed column instead of debit + credit. */
    public const FIELDS = ['date' => 'Date', 'description' => 'Description', 'reference' => 'Reference number', 'debit' => 'Debit (withdrawal)', 'credit' => 'Credit (deposit)', 'amount' => 'Signed amount (instead of debit/credit)', 'balance' => 'Balance'];
    public const DELIMITERS = [',' => 'Comma', ';' => 'Semicolon', "\t" => 'Tab', '|' => 'Pipe'];

    public function __construct(private readonly Database $db)
    {
    }

    public static function store(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/bank-statements'), 10 * 1024 * 1024);
    }

    // ---------------------------------------------------------------- statements

    /**
     * @param array{name: string, tmp_name: string, error: int, size: int} $file
     * @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor
     */
    public function upload(array $acc, array $file, array $data, array $actor, bool $httpUpload = true): int
    {
        if ((int) $acc['is_archived'] === 1 || in_array($acc['status'], ['draft', 'pending_approval'], true)) {
            throw new DomainRuleException('Statements can be added to active, inactive, frozen or closed accounts only.');
        }
        foreach (['opening_balance', 'closing_balance'] as $k) {
            $data[$k] = str_replace(',', '', trim((string) ($data[$k] ?? '')));
        }
        $v = Validator::make($data, ['period_from' => 'required|date', 'period_to' => 'required|date|before_or_equal:today', 'opening_balance' => 'nullable|regex:/^-?\d{1,13}(\.\d{1,2})?$/', 'closing_balance' => 'nullable|regex:/^-?\d{1,13}(\.\d{1,2})?$/', 'notes' => 'nullable|string|max:500'],
            ['period_from' => 'Statement from', 'period_to' => 'Statement to']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $c = $v->validated();
        if ($c['period_to'] < $c['period_from']) {
            throw ValidationException::withMessage('period_to', 'The end date must be on or after the start date.');
        }
        $store = self::store();
        $info = $store->validate($file, $httpUpload, 'statement', ['csv', 'pdf', 'xlsx']);
        $sha = (string) hash_file('sha256', $file['tmp_name']);
        $dup = $this->db->scalar("SELECT id FROM bank_statements WHERE bank_account_id = ? AND file_sha256 = ? AND status = 'active'", [$acc['id'], $sha]);
        if ($dup) {
            throw ValidationException::withMessage('statement', 'This exact file was already uploaded for this account (statement #' . $dup . ').');
        }
        $saved = $store->save($file, $info['ext'], $httpUpload);
        $id = $this->db->insert('bank_statements', [
            'bank_account_id' => (int) $acc['id'], 'period_from' => $c['period_from'], 'period_to' => $c['period_to'],
            'opening_balance' => ($c['opening_balance'] ?? '') !== '' ? Money::toDb(Money::parseSigned((string) $c['opening_balance'])) : null,
            'closing_balance' => ($c['closing_balance'] ?? '') !== '' ? Money::toDb(Money::parseSigned((string) $c['closing_balance'])) : null,
            'original_name' => $info['original'], 'stored_name' => $saved['stored'], 'mime_type' => $info['mime'], 'size_bytes' => $info['size'], 'file_sha256' => $saved['sha256'],
            'import_status' => 'not_imported', 'notes' => ($c['notes'] ?? '') ?: null, 'status' => 'active', 'uploaded_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        App::activity()->log('bank.statement_uploaded', "Uploaded statement \"{$info['original']}\" ({$c['period_from']} – {$c['period_to']}) for {$acc['account_code']}", 'bank_account', (int) $acc['id'],
            module: 'bank_accounts', newValues: ['entity' => 'statement', 'statement_id' => $id, 'file' => $info['original'], 'period_from' => $c['period_from'], 'period_to' => $c['period_to']]);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT s.*, a.account_code, a.display_name AS account_name, u.name AS uploaded_by_name FROM bank_statements s JOIN bank_accounts a ON a.id = s.bank_account_id
            LEFT JOIN users u ON u.id = s.uploaded_by WHERE s.id = ?', [$id]);
    }

    /** @param array{0: string, 1: list<mixed>} $scope alias a @return list<array<string, mixed>> */
    public function list(array $scope, ?int $accountId = null, bool $withArchived = false): array
    {
        [$w, $p] = $scope;
        if ($accountId !== null) {
            $w .= ' AND s.bank_account_id = ?';
            $p[] = $accountId;
        }
        return $this->db->select("SELECT s.*, a.account_code, a.display_name AS account_name, u.name AS uploaded_by_name,
                (SELECT COUNT(*) FROM bank_statement_rows r JOIN bank_statement_imports i ON i.id = r.import_id WHERE r.statement_id = s.id AND i.status = 'imported' AND r.status = 'valid') AS rows_imported,
                (SELECT COUNT(*) FROM bank_statement_rows r JOIN bank_statement_imports i ON i.id = r.import_id WHERE r.statement_id = s.id AND i.status = 'imported' AND r.status = 'valid' AND r.match_status = 'unmatched') AS rows_unmatched
            FROM bank_statements s JOIN bank_accounts a ON a.id = s.bank_account_id LEFT JOIN users u ON u.id = s.uploaded_by
            WHERE {$w}" . ($withArchived ? '' : " AND s.status = 'active'") . ' ORDER BY s.period_to DESC, s.id DESC LIMIT 500', $p);
    }

    /** @return list<array<string, mixed>> */
    public function imports(int $statementId): array
    {
        return $this->db->select('SELECT i.*, cu.name AS created_by_name, iu.name AS imported_by_name FROM bank_statement_imports i LEFT JOIN users cu ON cu.id = i.created_by
            LEFT JOIN users iu ON iu.id = i.imported_by WHERE i.statement_id = ? ORDER BY i.id DESC', [$statementId]);
    }

    /** @return list<array<string, mixed>> */
    public function rows(int $importId, int $limit = 1000): array
    {
        return $this->db->select('SELECT * FROM bank_statement_rows WHERE import_id = ? ORDER BY row_no LIMIT ' . max(1, $limit), [$importId]);
    }

    /** @param array<string, mixed> $st @param array<string, mixed> $actor */
    public function archive(array $st, string $reason, array $actor): void
    {
        if ($st['status'] !== 'active') {
            throw new DomainRuleException('Already archived.');
        }
        if ($this->db->scalar("SELECT 1 FROM bank_reconciliation_matches m JOIN bank_statement_rows r ON r.id = m.statement_row_id WHERE r.statement_id = ? AND m.status = 'active'", [$st['id']])) {
            throw new DomainRuleException('Lines of this statement are matched in a reconciliation. Unmatch them first; matched statements are kept as evidence.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('bank_statements', ['status' => 'archived', 'archive_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()], ['id' => $st['id']]);
        App::activity()->log('bank.statement_archived', "Archived statement #{$st['id']} of {$st['account_code']}", 'bank_account', (int) $st['bank_account_id'], module: 'bank_accounts', newValues: ['statement_id' => (int) $st['id'], 'reason' => $reason]);
    }

    /** @param array<string, mixed> $st */
    public function response(array $st): Response
    {
        App::activity()->log('bank.statement_downloaded', "Downloaded statement \"{$st['original_name']}\" of {$st['account_code']}", 'bank_account', (int) $st['bank_account_id'], module: 'bank_accounts', newValues: ['statement_id' => (int) $st['id']]);
        return self::store()->response((string) $st['stored_name'], (string) $st['mime_type'], (string) $st['original_name'], false);
    }

    // ---------------------------------------------------------------- CSV import

    /** First lines of the CSV for the mapping screen. @param array<string, mixed> $st @return list<list<string>> */
    public function sample(array $st, string $delimiter, int $lines = 8): array
    {
        $out = [];
        foreach ($this->lines($st, $delimiter) as $i => $cells) {
            if ($i >= $lines) {
                break;
            }
            $out[] = $cells;
        }
        return $out;
    }

    /** Best guess of the column mapping from a header row. @param list<string> $header @return array<string, int|null> */
    public static function guessMap(array $header): array
    {
        $map = array_fill_keys(array_keys(self::FIELDS), null);
        $valueDate = null;
        foreach ($header as $i => $h) {
            $h = mb_strtolower(trim($h));
            if ($valueDate === null && str_contains($h, 'date') && str_contains($h, 'value')) {
                $valueDate = $i; // used only when the file has no transaction-date column
            }
            $field = match (true) {
                str_contains($h, 'date') && !str_contains($h, 'value') => 'date',
                str_contains($h, 'narration') || str_contains($h, 'description') || str_contains($h, 'particular') || str_contains($h, 'remark') => 'description',
                str_contains($h, 'ref') || str_contains($h, 'chq') || str_contains($h, 'cheque') || str_contains($h, 'utr') => 'reference',
                str_contains($h, 'withdraw') || str_contains($h, 'debit') || $h === 'dr' => 'debit',
                str_contains($h, 'deposit') || str_contains($h, 'credit') || $h === 'cr' => 'credit',
                str_contains($h, 'balance') => 'balance',
                str_contains($h, 'amount') => 'amount',
                default => null,
            };
            if ($field !== null && $map[$field] === null) {
                $map[$field] = $i;
            }
        }
        $map['date'] ??= $valueDate;
        if ($map['debit'] !== null || $map['credit'] !== null) {
            $map['amount'] = null;
        }
        return $map;
    }

    /**
     * The first supported date format that parses every sampled value of the date column
     * (ambiguous files keep DD/MM-first order, the Indian convention). Null = no match.
     *
     * @param list<list<string>> $rows data rows (header excluded)
     */
    public static function guessDateFormat(array $rows, ?int $col): ?string
    {
        $values = $col === null ? [] : array_values(array_filter(array_map(static fn (array $r): string => trim((string) ($r[$col] ?? '')), $rows), static fn (string $v): bool => $v !== ''));
        if ($values === []) {
            return null;
        }
        foreach (array_keys(self::DATE_FORMATS) as $format) {
            $ok = true;
            foreach ($values as $v) {
                $d = \DateTimeImmutable::createFromFormat('!' . $format, $v);
                if ($d === false || $d->format($format) !== $v) {
                    $ok = false;
                    break;
                }
            }
            if ($ok) {
                return $format;
            }
        }
        return null;
    }

    /**
     * Parse the CSV with a mapping into a preview (replaces an earlier preview). Each line is
     * validated; lines already imported for this account are flagged as duplicates.
     * @param array<string, mixed> $st @param array<string, mixed> $opts map, date_format, delimiter, has_header @param array<string, mixed> $actor
     */
    public function preview(array $st, array $opts, array $actor): int
    {
        if ($st['status'] !== 'active') {
            throw new DomainRuleException('Archived statements cannot be imported.');
        }
        if (!str_ends_with(strtolower((string) $st['original_name']), '.csv')) {
            throw new DomainRuleException('Only CSV statements can be imported. PDF and Excel statements are kept as files.');
        }
        if ($st['import_status'] === 'imported') {
            throw new DomainRuleException('This statement has already been imported. Upload a new statement file for further lines.');
        }
        $delimiter = array_key_exists((string) ($opts['delimiter'] ?? ','), self::DELIMITERS) ? (string) $opts['delimiter'] : ',';
        $format = isset(self::DATE_FORMATS[$opts['date_format'] ?? '']) ? (string) $opts['date_format'] : 'd/m/Y';
        $hasHeader = !empty($opts['has_header']);
        $map = [];
        foreach (array_keys(self::FIELDS) as $f) {
            $raw = $opts['map'][$f] ?? '';
            $map[$f] = $raw === '' || $raw === null ? null : max(0, (int) $raw);
        }
        if ($map['date'] === null) {
            throw ValidationException::withMessage('map', 'Map the date column.');
        }
        if ($map['amount'] === null && ($map['debit'] === null || $map['credit'] === null)) {
            throw ValidationException::withMessage('map', 'Map both the debit and credit columns, or one signed amount column.');
        }
        return $this->db->transaction(function (Database $db) use ($st, $map, $format, $delimiter, $hasHeader, $actor): int {
            foreach ($db->select("SELECT id FROM bank_statement_imports WHERE statement_id = ? AND status = 'preview'", [$st['id']]) as $old) {
                $db->delete('bank_statement_rows', ['import_id' => $old['id']]);
                $db->update('bank_statement_imports', ['status' => 'discarded', 'updated_at' => now()], ['id' => $old['id']]);
            }
            $importId = $db->insert('bank_statement_imports', ['statement_id' => (int) $st['id'], 'column_map' => json_encode($map), 'date_format' => $format, 'delimiter' => $delimiter,
                'has_header' => $hasHeader ? 1 : 0, 'status' => 'preview', 'created_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            $counts = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicate' => 0];
            $prevBalance = null;
            foreach ($this->lines($st, $delimiter) as $i => $cells) {
                if ($i === 0 && $hasHeader) {
                    continue;
                }
                if (count(array_filter($cells, static fn (string $c): bool => trim($c) !== '')) === 0) {
                    continue;
                }
                if (++$counts['total'] > self::MAX_ROWS) {
                    throw new DomainRuleException('The file has more than ' . self::MAX_ROWS . ' lines. Split the statement into smaller periods.');
                }
                $row = $this->parseLine($cells, $map, $format, $st, $prevBalance);
                if ($row['balance'] !== null) {
                    $prevBalance = Money::parse($row['balance']);
                }
                $hash = hash('sha256', implode('|', [$st['bank_account_id'], $row['txn_date'], $row['debit'], $row['credit'], mb_strtolower((string) $row['reference_no']), mb_strtolower(preg_replace('/\s+/', ' ', (string) $row['description']) ?? '')]));
                $status = $row['errors'] === [] ? 'valid' : 'invalid';
                if ($status === 'valid' && $db->scalar("SELECT 1 FROM bank_statement_rows r JOIN bank_statement_imports i ON i.id = r.import_id WHERE r.bank_account_id = ? AND r.row_hash = ? AND i.status = 'imported' AND r.status = 'valid'", [$st['bank_account_id'], $hash])) {
                    $status = 'duplicate';
                    $row['errors'][] = 'Already imported from an earlier statement of this account.';
                }
                $counts[$status]++;
                $db->insert('bank_statement_rows', ['import_id' => $importId, 'statement_id' => (int) $st['id'], 'bank_account_id' => (int) $st['bank_account_id'], 'row_no' => $i + 1,
                    'txn_date' => $row['txn_date'], 'description' => $row['description'], 'reference_no' => $row['reference_no'], 'debit' => $row['debit'], 'credit' => $row['credit'], 'balance' => $row['balance'],
                    'row_hash' => $hash, 'status' => $status, 'errors' => $row['errors'] === [] && $row['warnings'] === [] ? null : mb_substr(implode(' ', array_merge($row['errors'], $row['warnings'])), 0, 500),
                    'match_status' => 'unmatched', 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($counts['total'] === 0) {
                throw new DomainRuleException('No statement lines were found. Check the delimiter and the "first line is a header" option.');
            }
            $db->update('bank_statement_imports', ['rows_total' => $counts['total'], 'rows_valid' => $counts['valid'], 'rows_invalid' => $counts['invalid'], 'rows_duplicate' => $counts['duplicate'], 'updated_at' => now()], ['id' => $importId]);
            $db->update('bank_statements', ['import_status' => 'previewed', 'updated_at' => now()], ['id' => $st['id']]);
            return $importId;
        });
    }

    /** Confirm a preview: valid lines become available for reconciliation. Nothing is posted. @param array<string, mixed> $import @param array<string, mixed> $actor */
    public function import(array $import, array $actor): void
    {
        if ($import['status'] !== 'preview') {
            throw new DomainRuleException('Only a preview can be imported.');
        }
        if ((int) $import['rows_valid'] === 0) {
            throw new DomainRuleException('There are no valid lines to import.');
        }
        $st = $this->find((int) $import['statement_id']);
        $this->db->transaction(function (Database $db) use ($import, $actor, $st): void {
            if ($db->scalar("SELECT 1 FROM bank_statement_imports WHERE statement_id = ? AND status = 'imported'", [$import['statement_id']])) {
                throw new DomainRuleException('This statement has already been imported.');
            }
            // Re-check duplicates at import time (another statement may have been imported since the preview).
            foreach ($db->select("SELECT id, row_hash FROM bank_statement_rows WHERE import_id = ? AND status = 'valid'", [$import['id']]) as $r) {
                if ($db->scalar("SELECT 1 FROM bank_statement_rows r JOIN bank_statement_imports i ON i.id = r.import_id WHERE r.bank_account_id = ? AND r.row_hash = ? AND i.status = 'imported' AND r.status = 'valid'", [$st['bank_account_id'], $r['row_hash']])) {
                    $db->update('bank_statement_rows', ['status' => 'duplicate', 'errors' => 'Already imported from an earlier statement of this account.'], ['id' => $r['id']]);
                }
            }
            $valid = (int) $db->scalar("SELECT COUNT(*) FROM bank_statement_rows WHERE import_id = ? AND status = 'valid'", [$import['id']]);
            $changed = $db->execute("UPDATE bank_statement_imports SET status = 'imported', rows_valid = ?, rows_duplicate = (SELECT COUNT(*) FROM bank_statement_rows WHERE import_id = ? AND status = 'duplicate'),
                imported_by = ?, imported_at = ?, updated_at = ? WHERE id = ? AND status = 'preview'", [$valid, $import['id'], (int) $actor['id'], now(), now(), $import['id']]);
            if ($changed !== 1) {
                throw new DomainRuleException('This import was changed by someone else.');
            }
            $db->update('bank_statements', ['import_status' => 'imported', 'updated_at' => now()], ['id' => $import['statement_id']]);
        });
        $fresh = $this->db->first('SELECT * FROM bank_statement_imports WHERE id = ?', [$import['id']]);
        App::activity()->log('bank.statement_imported', "Imported {$fresh['rows_valid']} statement line(s) for {$st['account_code']} ({$fresh['rows_duplicate']} duplicate, {$fresh['rows_invalid']} invalid skipped)", 'bank_account', (int) $st['bank_account_id'],
            module: 'bank_accounts', newValues: ['statement_id' => (int) $st['id'], 'import_id' => (int) $import['id'], 'valid' => (int) $fresh['rows_valid'], 'duplicate' => (int) $fresh['rows_duplicate'], 'invalid' => (int) $fresh['rows_invalid']]);
    }

    /** @param array<string, mixed> $import */
    public function discard(array $import): void
    {
        if ($import['status'] !== 'preview') {
            throw new DomainRuleException('Only a preview can be discarded.');
        }
        $this->db->transaction(function (Database $db) use ($import): void {
            $db->delete('bank_statement_rows', ['import_id' => $import['id']]);
            $db->update('bank_statement_imports', ['status' => 'discarded', 'updated_at' => now()], ['id' => $import['id']]);
            if (!$db->scalar("SELECT 1 FROM bank_statement_imports WHERE statement_id = ? AND status IN ('preview', 'imported')", [$import['statement_id']])) {
                $db->update('bank_statements', ['import_status' => 'not_imported', 'updated_at' => now()], ['id' => $import['statement_id']]);
            }
        });
    }

    /**
     * Parse one CSV line. Amounts accept 1,23,456.78 / ₹ / "Dr"/"Cr" suffixes / (negative).
     * @param list<string> $cells @param array<string, int|null> $map @param array<string, mixed> $st
     * @return array{txn_date: ?string, description: ?string, reference_no: ?string, debit: string, credit: string, balance: ?string, errors: list<string>, warnings: list<string>}
     */
    private function parseLine(array $cells, array $map, string $format, array $st, ?int $prevBalance): array
    {
        $cell = static fn (?int $i): string => $i === null ? '' : trim((string) ($cells[$i] ?? ''));
        $errors = [];
        $warnings = [];
        $date = null;
        $rawDate = $cell($map['date']);
        $d = \DateTimeImmutable::createFromFormat('!' . $format, $rawDate);
        $dErr = \DateTimeImmutable::getLastErrors();
        if ($d === false || ($dErr !== false && ($dErr['warning_count'] > 0 || $dErr['error_count'] > 0))) {
            $errors[] = "Date \"{$rawDate}\" does not match the format " . self::DATE_FORMATS[$format] . '.';
        } else {
            $date = $d->format('Y-m-d');
            if ($date < $st['period_from'] || $date > $st['period_to']) {
                $errors[] = 'Date is outside the statement period.';
            }
        }
        $debit = 0;
        $credit = 0;
        if ($map['amount'] !== null) {
            $amt = self::amount($cell($map['amount']));
            if ($amt === null) {
                $errors[] = 'Amount is not a number.';
            } elseif ($amt < 0) {
                $debit = -$amt;
            } else {
                $credit = $amt;
            }
        } else {
            $dv = self::amount($cell($map['debit']), true);
            $cv = self::amount($cell($map['credit']), true);
            if ($dv === null || $cv === null) {
                $errors[] = 'Debit or credit is not a number.';
            } else {
                [$debit, $credit] = [abs($dv), abs($cv)];
            }
        }
        if ($errors === [] && (($debit > 0) === ($credit > 0))) {
            $errors[] = $debit === 0 ? 'The line has no amount.' : 'A line cannot have both a debit and a credit.';
        }
        $balance = null;
        if ($map['balance'] !== null && $cell($map['balance']) !== '') {
            $b = self::amount($cell($map['balance']));
            if ($b === null) {
                $warnings[] = 'Balance is not a number (ignored).';
            } else {
                $balance = Money::toDb($b);
                if ($prevBalance !== null && $errors === [] && $prevBalance + $credit - $debit !== $b) {
                    $warnings[] = 'Running balance does not follow from the previous line.';
                }
            }
        }
        $desc = $cell($map['description']);
        $ref = $cell($map['reference']);
        return ['txn_date' => $date, 'description' => $desc !== '' ? mb_substr($desc, 0, 500) : null, 'reference_no' => $ref !== '' ? mb_substr($ref, 0, 100) : null,
            'debit' => Money::toDb($debit), 'credit' => Money::toDb($credit), 'balance' => $balance, 'errors' => $errors, 'warnings' => $warnings];
    }

    /** Parse a statement amount to paise; '' = 0 when $emptyIsZero. */
    public static function amount(string $raw, bool $emptyIsZero = false): ?int
    {
        $s = trim(str_replace(['₹', 'INR', 'Rs.', 'Rs', ',', ' ', "\u{00A0}"], '', $raw));
        if ($s === '' || $s === '-') {
            return $emptyIsZero ? 0 : null;
        }
        $negative = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $s = $m[1];
            $negative = true;
        }
        if (preg_match('/^(.*?)(dr|cr)\.?$/i', $s, $m)) {
            $s = $m[1];
            $negative = $negative || strtolower($m[2]) === 'dr';
        }
        if (str_starts_with($s, '-')) {
            $negative = !$negative;
            $s = substr($s, 1);
        }
        if (!preg_match('/^\d+(\.\d{1,2})?$/', $s)) {
            return null;
        }
        $paise = Money::parse($s);
        return $negative ? -$paise : $paise;
    }

    /** @param array<string, mixed> $st @return \Generator<int, list<string>> */
    private function lines(array $st, string $delimiter): \Generator
    {
        $path = self::store()->path((string) $st['stored_name']);
        if ($path === null) {
            throw new DomainRuleException('The statement file is missing from storage.');
        }
        $content = (string) file_get_contents($path);
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $h = fopen('php://temp', 'r+');
        fwrite($h, $content);
        rewind($h);
        $i = 0;
        while (($cells = fgetcsv($h, 0, $delimiter, '"', '')) !== false) {
            yield $i++ => array_map(static fn ($c): string => (string) $c, $cells);
        }
        fclose($h);
    }
}
