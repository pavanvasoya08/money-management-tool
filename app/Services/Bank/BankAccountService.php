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
use App\Support\FieldCipher;
use App\Support\Money;

/**
 * Bank account master: creation (draft → approval → active), key-detail changes and
 * closure through the approval engine, status changes, the central funding account,
 * archive/restore and history. Account numbers are encrypted at rest; only the last
 * four digits are ever logged. Accounts are never deleted.
 */
final class BankAccountService
{
    public const SUBJECT = 'bank_change_request';
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'active' => 'Active', 'inactive' => 'Inactive', 'frozen' => 'Frozen / blocked', 'closed' => 'Closed'];
    /** target => [allowed from, permission action, label] (closure goes through approval). */
    public const TRANSITIONS = [
        'active' => [['inactive', 'frozen'], null, 'Activate / unfreeze'],
        'inactive' => [['active'], 'deactivate', 'Deactivate'],
        'frozen' => [['active', 'inactive'], 'freeze', 'Freeze / block'],
        'closed' => [['active', 'inactive', 'frozen'], 'close', 'Close'],
    ];
    /** Changes to these on a non-draft account go through the key-detail change workflow. */
    public const KEY_FIELDS = ['bank_name', 'account_holder_name', 'account_number', 'ifsc_code', 'account_type_id', 'currency', 'allow_overdraft', 'overdraft_limit'];
    public const DIRECT_FIELDS = ['display_name', 'branch_name', 'micr_code', 'purpose', 'internal_notes', 'opening_date'];
    public const CURRENCIES = ['INR' => 'INR – Indian rupee', 'USD' => 'USD – US dollar', 'EUR' => 'EUR – Euro', 'GBP' => 'GBP – Pound sterling', 'AED' => 'AED – UAE dirham', 'SGD' => 'SGD – Singapore dollar'];
    private const WORKFLOWS = ['create' => 'bank_account_create', 'update' => 'bank_account_update', 'close' => 'bank_account_close', 'reconciliation_reopen' => 'bank_reconciliation_reopen'];
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

    // ---------------------------------------------------------------- reading

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT a.*, t.name AS type_name, t.code AS type_code, cu.name AS created_by_name, uu.name AS updated_by_name
             FROM bank_accounts a JOIN bank_account_types t ON t.id = a.account_type_id
             LEFT JOIN users cu ON cu.id = a.created_by LEFT JOIN users uu ON uu.id = a.updated_by WHERE a.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function central(): ?array
    {
        $id = $this->db->scalar('SELECT id FROM bank_accounts WHERE is_central_funding = 1 AND is_archived = 0 LIMIT 1');
        return $id === null ? null : $this->find((int) $id);
    }

    /**
     * @param array<string, mixed> $filters q, status, type, bank, partner, gst, activity, archived, central
     * @param array{0: string, 1: list<mixed>} $scope constraint on alias a
     * @return array{0: string, 1: list<mixed>}
     */
    public function filterSql(array $filters, array $scope): array
    {
        [$where, $params] = $scope;
        $sql = [$where];
        $status = (string) ($filters['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $sql[] = 'a.status = ?';
            $params[] = $status;
        }
        if (($filters['archived'] ?? '') === 'only') {
            $sql[] = 'a.is_archived = 1';
        } elseif (($filters['archived'] ?? '') !== 'with') {
            $sql[] = 'a.is_archived = 0';
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $sql[] = '(LOWER(a.display_name) LIKE ? OR LOWER(a.bank_name) LIKE ? OR LOWER(a.account_code) LIKE ? OR a.account_number_last4 = ? OR LOWER(a.ifsc_code) LIKE ?)';
            array_push($params, $like, $like, $like, substr(preg_replace('/\D/', '', $q) ?? '', -4) ?: '----', $like);
        }
        if (($bank = trim((string) ($filters['bank'] ?? ''))) !== '') {
            $sql[] = 'LOWER(a.bank_name) = ?';
            $params[] = mb_strtolower($bank);
        }
        foreach (['type' => 'a.account_type_id = ?', 'partner' => "a.id IN (SELECT bank_account_id FROM bank_account_partner_mappings WHERE status = 'active' AND partner_id = ?)",
            'gst' => "a.id IN (SELECT bank_account_id FROM bank_account_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)",
            'activity' => "a.id IN (SELECT bank_account_id FROM bank_account_business_mappings WHERE status = 'active' AND business_activity_id = ?)"] as $k => $cond) {
            if ((int) ($filters[$k] ?? 0) > 0) {
                $sql[] = $cond;
                $params[] = (int) $filters[$k];
            }
        }
        if (($filters['central'] ?? '') === '1') {
            $sql[] = 'a.is_central_funding = 1';
        }
        return [implode(' AND ', $sql), $params];
    }

    /** @param array<string, mixed> $filters @param array{0: string, 1: list<mixed>} $scope */
    public function paginate(array $filters, int $page, int $perPage, array $scope): Paginator
    {
        [$w, $p] = $this->filterSql($filters, $scope);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM bank_accounts a WHERE {$w}", $p);
        $order = match ((string) ($filters['sort'] ?? '')) {
            'bank' => 'a.bank_name, a.display_name', 'status' => 'a.status, a.display_name', 'code' => 'a.account_code', 'updated' => 'a.updated_at DESC', default => 'a.is_central_funding DESC, a.display_name',
        };
        $rows = $this->db->select(
            "SELECT a.*, t.name AS type_name FROM bank_accounts a JOIN bank_account_types t ON t.id = a.account_type_id WHERE {$w} ORDER BY {$order} LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage),
            $p
        );
        $ledger = new BankLedgerService($this->db);
        $balances = $ledger->balances(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['balance_paise'] = $balances[(int) $r['id']] ?? Money::fromDb($r['opening_balance']);
        }
        return new Paginator($rows, $total, $page, $perPage);
    }

    /** @return list<string> distinct bank names in scope (filters) @param array{0: string, 1: list<mixed>} $scope */
    public function bankNames(array $scope): array
    {
        [$w, $p] = $scope;
        return array_map(static fn (array $r): string => (string) $r['bank_name'], $this->db->select("SELECT DISTINCT a.bank_name FROM bank_accounts a WHERE {$w} ORDER BY a.bank_name", $p));
    }

    /** @return list<array<string, mixed>> */
    public function types(bool $activeOnly = true): array
    {
        return $this->db->select('SELECT * FROM bank_account_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    }

    /** Masked number for general display, e.g. XXXXXX4821. */
    public static function masked(array $account): string
    {
        return 'XXXXXX' . (string) ($account['account_number_last4'] ?? '');
    }

    /** Full number for users with bank_accounts.view_full_account_number (and no hiding field rule). Logged. @param array<string, mixed> $account @param array<string, mixed> $user */
    public function fullNumber(array $account, array $user): ?string
    {
        if (!App::gate()->allows($user, 'bank_accounts.view_full_account_number')) {
            return null;
        }
        if (!App::fields()->access($user, 'bank_accounts', 'account_number')->showsRealValue()) {
            return null;
        }
        return FieldCipher::decrypt((string) $account['account_number_encrypted']);
    }

    // ---------------------------------------------------------------- create / edit

    /**
     * Create a draft account (optionally submitting it for approval at once).
     * @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{0: int, 1: array<string, mixed>|null} [id, change request when submitted]
     */
    public function create(array $data, array $actor, bool $submit = false): array
    {
        $clean = $this->validate($data, null);
        $this->duplicateCheck($clean, null, !empty($data['duplicate_ok']));
        $id = $this->db->transaction(function (Database $db) use ($clean, $actor): int {
            $row = $this->storable($clean) + [
                'account_code' => $this->nextCode(), 'status' => 'draft',
                'opening_balance' => Money::toDb($clean['opening_paise']), 'opening_balance_date' => $clean['opening_balance_date'],
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
            ];
            $id = $db->insert('bank_accounts', $row);
            $this->history($id, 'created', null, 'draft', null, $this->auditView($row), null, null, $actor);
            return $id;
        });
        $acc = $this->find($id);
        App::activity()->log('bank.account_created', "Added bank account {$acc['account_code']} ({$acc['display_name']}, " . self::masked($acc) . ')', 'bank_account', $id,
            module: 'bank_accounts', newValues: $this->auditView($acc));
        $change = $submit ? $this->submit($acc, $actor) : null;
        return [$id, $change];
    }

    /**
     * Update an account. Drafts: everything, directly. Other accounts: direct fields at once,
     * key fields through the key-detail change workflow; the opening balance is then locked.
     * @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{direct: list<string>, key: list<string>, change: array<string, mixed>|null}
     */
    public function update(array $acc, array $data, array $actor, ?string $reason = null): array
    {
        $this->assertWritable($acc);
        if ($acc['status'] === 'pending_approval') {
            throw new DomainRuleException('This account is waiting for approval. Withdraw or decide the request first.');
        }
        $clean = $this->validate($data, $acc);
        $this->duplicateCheck($clean, (int) $acc['id'], !empty($data['duplicate_ok']));
        $new = $this->storable($clean);
        if (($new['account_number_hash'] ?? null) === $acc['account_number_hash']) {
            unset($new['account_number_encrypted'], $new['account_number_hash'], $new['account_number_last4']); // same number re-entered
        }
        $isDraft = $acc['status'] === 'draft';
        $old = [];
        $changed = [];
        foreach ($new as $k => $v) {
            $same = $k === 'overdraft_limit' ? Money::fromDb($acc[$k] ?? '0') === Money::fromDb($v ?? '0') : (string) ($acc[$k] ?? '') === (string) ($v ?? '');
            if (!$same) {
                $changed[$k] = $v;
                $old[$k] = $acc[$k];
            }
        }
        if ($isDraft) {
            foreach (['opening_balance' => Money::toDb($clean['opening_paise']), 'opening_balance_date' => $clean['opening_balance_date']] as $k => $v) {
                if ((string) $acc[$k] !== (string) $v) {
                    $changed[$k] = $v;
                    $old[$k] = $acc[$k];
                }
            }
        } elseif (Money::toDb($clean['opening_paise']) !== Money::toDb(Money::fromDb($acc['opening_balance'])) || $clean['opening_balance_date'] !== $acc['opening_balance_date']) {
            throw ValidationException::withMessage('opening_balance', 'After activation the opening balance can only be changed with an opening-balance adjustment entry (Transactions → New entry).');
        }
        $keyChanges = [];
        $directChanges = $changed;
        if (!$isDraft) {
            foreach ($changed as $k => $v) {
                if (in_array($k === 'account_number_encrypted' || $k === 'account_number_hash' || $k === 'account_number_last4' ? 'account_number' : $k, self::KEY_FIELDS, true)) {
                    $keyChanges[$k] = $v;
                    unset($directChanges[$k]);
                }
            }
        }
        if ($directChanges !== []) {
            $this->db->update('bank_accounts', $directChanges + ['updated_by' => (int) $actor['id'], 'updated_at' => now(), 'rejection_reason' => null], ['id' => $acc['id']]);
            $oldView = $this->auditView(array_intersect_key($acc, $directChanges));
            $newView = $this->auditView($directChanges);
            $this->history((int) $acc['id'], 'updated', null, null, $oldView, $newView, $reason, null, $actor);
            App::activity()->log('bank.account_updated', "Updated bank account {$acc['account_code']}", 'bank_account', (int) $acc['id'], module: 'bank_accounts', oldValues: $oldView, newValues: $newView);
        }
        $change = null;
        if ($keyChanges !== []) {
            if (trim((string) $reason) === '') {
                throw ValidationException::withMessage('change_reason', 'Give a reason for changing key details.');
            }
            $change = $this->request($acc, 'update', $this->auditView(array_intersect_key($acc, $keyChanges)), $keyChanges, $reason, $actor, 'bank_accounts.edit');
        }
        return ['direct' => array_keys($directChanges), 'key' => array_keys($keyChanges), 'change' => $change];
    }

    /** Submit a draft account for approval (applies at once when the workflow is inactive). @param array<string, mixed> $acc @param array<string, mixed> $actor */
    public function submit(array $acc, array $actor): array
    {
        if ($acc['status'] !== 'draft') {
            throw new DomainRuleException('Only draft accounts can be submitted.');
        }
        $this->assertWritable($acc);
        $this->db->update('bank_accounts', ['status' => 'pending_approval', 'rejection_reason' => null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $acc['id']]);
        $this->history((int) $acc['id'], 'status', 'draft', 'pending_approval', null, null, 'Submitted for approval', null, $actor);
        try {
            return $this->request($acc, 'create', null, ['status' => 'active'], 'New bank account', $actor, 'bank_accounts.create');
        } catch (\Throwable $e) {
            $this->db->update('bank_accounts', ['status' => 'draft'], ['id' => $acc['id']]);
            throw $e;
        }
    }

    // ---------------------------------------------------------------- status / central / archive

    /** @param array<string, mixed> $acc @param array<string, mixed> $actor @return array<string, mixed>|null change request (closure) */
    public function changeStatus(array $acc, string $to, ?string $reason, ?string $date, array $actor): ?array
    {
        $this->assertWritable($acc);
        if (!isset(self::TRANSITIONS[$to])) {
            throw ValidationException::withMessage('status', 'Choose a valid status.');
        }
        [$from, , $label] = self::TRANSITIONS[$to];
        if (!in_array($acc['status'], $from, true)) {
            throw new DomainRuleException("A {$acc['status']} account cannot be changed to {$to}.");
        }
        $reason = trim((string) $reason);
        if (in_array($to, ['frozen', 'closed', 'inactive'], true) && $reason === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $date = BusinessDate::isValid((string) $date) ? (string) $date : BusinessDate::today();
        if ($to === 'closed') {
            $this->assertClosable($acc);
            return $this->request($acc, 'close', ['status' => $acc['status']], ['status' => 'closed', 'closed_on' => $date], $reason, $actor, 'bank_accounts.close');
        }
        $this->db->update('bank_accounts', ['status' => $to, 'status_reason' => $reason ?: null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $acc['id']]);
        $this->history((int) $acc['id'], 'status', $acc['status'], $to, null, null, $reason ?: null, $date, $actor);
        App::activity()->log('bank.status_changed', "{$label}: bank account {$acc['account_code']} ({$acc['status']} → {$to})", 'bank_account', (int) $acc['id'],
            module: 'bank_accounts', oldValues: ['status' => $acc['status']], newValues: ['status' => $to, 'reason' => $reason ?: null, 'effective_date' => $date]);
        return null;
    }

    /**
     * Designate (or remove) the central joint funding account. Goes through the key-detail
     * change workflow, so with that workflow active a second person must approve the switch.
     * @param array<string, mixed> $acc @param array<string, mixed> $actor
     */
    public function requestCentral(array $acc, bool $central, string $reason, array $actor): array
    {
        $this->assertWritable($acc);
        if (!App::gate()->allows($actor, 'bank_accounts.manage_central_account')) {
            throw new \App\Exceptions\AuthorizationException('bank_accounts.manage_central_account', 'Only users allowed to manage the central account can change it.');
        }
        if ($central && $acc['status'] !== 'active') {
            throw new DomainRuleException('Only an active account can be the central funding account.');
        }
        if ((int) $acc['is_central_funding'] === ($central ? 1 : 0)) {
            throw new DomainRuleException($central ? 'This account is already the central funding account.' : 'This account is not the central funding account.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $current = $this->central();
        return $this->request($acc, 'update', ['is_central_funding' => (int) $acc['is_central_funding'], 'replaces' => $current['account_code'] ?? null],
            ['is_central_funding' => $central ? 1 : 0], $reason, $actor, 'bank_accounts.manage_central_account');
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $actor */
    public function archive(array $acc, string $reason, array $actor): void
    {
        if ((int) $acc['is_archived'] === 1) {
            throw new DomainRuleException('Already archived.');
        }
        if (!in_array($acc['status'], ['closed', 'draft'], true)) {
            throw new DomainRuleException('Only closed accounts, or drafts that were never activated, can be archived. Close the account first.');
        }
        if ((int) $acc['is_central_funding'] === 1) {
            throw new DomainRuleException('The central funding account cannot be archived.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('bank_accounts', ['is_archived' => 1, 'archived_at' => now(), 'archived_by' => (int) $actor['id'], 'archive_reason' => mb_substr($reason, 0, 255), 'updated_at' => now()], ['id' => $acc['id']]);
        $this->history((int) $acc['id'], 'archived', null, null, null, null, $reason, null, $actor);
        App::activity()->log('bank.account_archived', "Archived bank account {$acc['account_code']}", 'bank_account', (int) $acc['id'], module: 'bank_accounts', newValues: ['reason' => $reason]);
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $actor */
    public function restore(array $acc, array $actor): void
    {
        if ((int) $acc['is_archived'] !== 1) {
            throw new DomainRuleException('This account is not archived.');
        }
        $this->db->update('bank_accounts', ['is_archived' => 0, 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null, 'updated_at' => now()], ['id' => $acc['id']]);
        $this->history((int) $acc['id'], 'restored', null, null, null, null, null, null, $actor);
        App::activity()->log('bank.account_restored', "Restored bank account {$acc['account_code']}", 'bank_account', (int) $acc['id'], module: 'bank_accounts');
    }

    /** Accounts may only receive entries while active. @param array<string, mixed> $acc */
    public static function assertUsable(array $acc, string $what = 'entries'): void
    {
        if ((int) $acc['is_archived'] === 1 || $acc['status'] !== 'active') {
            throw new DomainRuleException("{$acc['display_name']} is " . strtolower(self::STATUSES[$acc['status']] ?? $acc['status']) . ((int) $acc['is_archived'] === 1 ? ' and archived' : '') . "; it cannot take {$what}.");
        }
    }

    /** @param array<string, mixed> $acc */
    public function assertWritable(array $acc): void
    {
        if ((int) $acc['is_archived'] === 1) {
            throw new DomainRuleException('Archived accounts are read-only. Restore the account first.');
        }
        if ($acc['status'] === 'closed') {
            throw new DomainRuleException('Closed accounts are read-only.');
        }
    }

    // ---------------------------------------------------------------- change requests & approvals

    /** @return array<string, mixed>|null */
    public function pendingChange(int $accountId): ?array
    {
        return $this->db->first("SELECT * FROM bank_change_requests WHERE bank_account_id = ? AND status = 'pending' AND change_type <> 'reconciliation_reopen' ORDER BY id DESC LIMIT 1", [$accountId]);
    }

    /** @return list<array<string, mixed>> */
    public function changes(int $accountId): array
    {
        $rows = $this->db->select('SELECT c.*, u.name AS requested_by_name FROM bank_change_requests c LEFT JOIN users u ON u.id = c.requested_by WHERE c.bank_account_id = ? ORDER BY c.id DESC', [$accountId]);
        foreach ($rows as &$r) {
            $r['old_values'] = json_decode((string) $r['old_values'], true);
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function change(int $id): ?array
    {
        $r = $this->db->first('SELECT c.*, a.account_code, a.display_name FROM bank_change_requests c JOIN bank_accounts a ON a.id = c.bank_account_id WHERE c.id = ?', [$id]);
        if ($r !== null) {
            $r['old_values'] = json_decode((string) $r['old_values'], true);
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $r;
    }

    /**
     * Create a change request and submit it. Inactive workflows auto-approve, which applies it.
     * @param array<string, mixed> $acc @param array<string, mixed>|null $old @param array<string, mixed> $new @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function request(array $acc, string $type, ?array $old, array $new, ?string $reason, array $actor, string $permission, ?int $reconciliationId = null): array
    {
        if ($type !== 'reconciliation_reopen' && $this->pendingChange((int) $acc['id']) !== null && $type !== 'create') {
            throw new DomainRuleException('Another change to this account is waiting for approval.');
        }
        $id = $this->db->insert('bank_change_requests', [
            'bank_account_id' => (int) $acc['id'], 'reconciliation_id' => $reconciliationId, 'change_type' => $type,
            'old_values' => $old === null ? null : json_encode($this->safe($old), JSON_UNESCAPED_UNICODE), 'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null, 'status' => 'pending', 'requested_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        App::activity()->log('bank.change_requested', 'Requested ' . str_replace('_', ' ', $type) . " for bank account {$acc['account_code']}", 'bank_account', (int) $acc['id'],
            module: 'bank_accounts', oldValues: $old === null ? null : $this->safe($old), newValues: $this->safe($new) + ['request' => $id, 'reason' => $reason]);
        $titles = ['create' => 'New bank account', 'update' => 'Bank account change', 'close' => 'Bank account closure', 'reconciliation_reopen' => 'Reopen reconciliation'];
        try {
            $req = App::approvals()->submit(self::WORKFLOWS[$type], self::SUBJECT, $id, 0.0, "{$titles[$type]}: {$acc['account_code']} — {$acc['display_name']}", $actor, $permission);
        } catch (\Throwable $e) {
            $this->db->update('bank_change_requests', ['status' => 'cancelled', 'decided_at' => now()], ['id' => $id]);
            throw $e;
        }
        $this->db->execute('UPDATE bank_change_requests SET approval_request_id = ? WHERE id = ? AND approval_request_id IS NULL', [$req['id'], $id]);
        return $this->change($id) ?? [];
    }

    /** Approval engine callback. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $change = $this->change((int) $request['subject_id']);
        if ($change === null || $change['status'] !== 'pending') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = $last !== null ? ['id' => (int) $last['acted_by']] : ['id' => (int) $change['requested_by']];
        $acc = $this->find((int) $change['bank_account_id']);
        $newStatus = match ($status) { 'approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled', default => null };
        if ($newStatus === null || $acc === null) {
            return;
        }
        $this->db->transaction(function () use ($change, $acc, $newStatus, $by, $last, $request): void {
            $this->db->update('bank_change_requests', ['status' => $newStatus, 'approval_request_id' => (int) $request['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $change['id']]);
            match ($change['change_type']) {
                'create' => $this->decideCreate($acc, $newStatus, $last['comment'] ?? null, $by),
                'update' => $newStatus === 'approved' ? $this->applyUpdate($acc, $change, $by) : null,
                'close' => $newStatus === 'approved' ? $this->applyClose($acc, $change, $by) : null,
                'reconciliation_reopen' => (new BankReconciliationService($this->db))->decideReopen($change, $newStatus, $by),
            };
        });
        App::activity()->log('bank.change_' . $newStatus, ucfirst($newStatus) . ' ' . str_replace('_', ' ', $change['change_type']) . " request for bank account {$acc['account_code']}", 'bank_account', (int) $acc['id'],
            module: 'bank_accounts', newValues: ['request' => (int) $change['id'], 'status' => $newStatus], userId: $by['id']);
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed> $acc @param array<string, mixed> $by */
    private function decideCreate(array $acc, string $status, ?string $comment, array $by): void
    {
        if ($acc['status'] !== 'pending_approval') {
            return;
        }
        $to = $status === 'approved' ? 'active' : 'draft';
        $this->db->update('bank_accounts', ['status' => $to, 'rejection_reason' => $status === 'rejected' ? $comment : null, 'updated_at' => now()], ['id' => $acc['id']]);
        $this->history((int) $acc['id'], 'status', 'pending_approval', $to, null, null, $status === 'rejected' ? 'Rejected: ' . ($comment ?? '') : ($status === 'cancelled' ? 'Request withdrawn' : 'Approved'), null, $by);
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $change @param array<string, mixed> $by */
    private function applyUpdate(array $acc, array $change, array $by): void
    {
        $new = $change['new_values'];
        if (array_key_exists('is_central_funding', $new)) {
            if ((int) $new['is_central_funding'] === 1) {
                if ($acc['status'] !== 'active') {
                    throw new DomainRuleException('Only an active account can become the central funding account.');
                }
                foreach ($this->db->select('SELECT id FROM bank_accounts WHERE is_central_funding = 1 AND id <> ?', [$acc['id']]) as $old) {
                    $this->db->update('bank_accounts', ['is_central_funding' => 0, 'updated_at' => now()], ['id' => $old['id']]);
                    $this->history((int) $old['id'], 'central', null, null, ['is_central_funding' => 1], ['is_central_funding' => 0], 'Replaced by ' . $acc['account_code'], null, $by);
                }
            }
            $this->db->update('bank_accounts', ['is_central_funding' => (int) $new['is_central_funding'], 'updated_at' => now()], ['id' => $acc['id']]);
            $this->history((int) $acc['id'], 'central', null, null, ['is_central_funding' => (int) $acc['is_central_funding']], ['is_central_funding' => (int) $new['is_central_funding']], $change['reason'], null, $by);
            return;
        }
        if (isset($new['account_number_hash'])) {
            $clash = $this->db->scalar('SELECT account_code FROM bank_accounts WHERE ifsc_code = ? AND account_number_hash = ? AND id <> ?', [$new['ifsc_code'] ?? $acc['ifsc_code'], $new['account_number_hash'], $acc['id']]);
            if ($clash) {
                throw new DomainRuleException("Account {$clash} already has this account number at this IFSC.");
            }
        }
        $this->db->update('bank_accounts', $new + ['updated_by' => $by['id'], 'updated_at' => now()], ['id' => $acc['id']]);
        $this->history((int) $acc['id'], 'updated', null, null, $change['old_values'], $this->auditView($new), $change['reason'], null, $by);
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $change @param array<string, mixed> $by */
    private function applyClose(array $acc, array $change, array $by): void
    {
        $this->assertClosable($acc);
        $this->db->update('bank_accounts', ['status' => 'closed', 'closed_on' => $change['new_values']['closed_on'] ?? BusinessDate::today(), 'status_reason' => $change['reason'], 'updated_at' => now()], ['id' => $acc['id']]);
        $this->history((int) $acc['id'], 'status', $acc['status'], 'closed', null, null, $change['reason'], $change['new_values']['closed_on'] ?? null, $by);
    }

    /** @param array<string, mixed> $acc */
    private function assertClosable(array $acc): void
    {
        if ((int) $acc['is_central_funding'] === 1) {
            throw new DomainRuleException('The central funding account cannot be closed. Designate another central account first.');
        }
        $balance = (new BankLedgerService($this->db))->balance((int) $acc['id']);
        if ($balance !== 0) {
            throw new DomainRuleException('The balance must be zero before closing (it is ' . Money::format($balance) . '). Transfer or withdraw the remaining amount first.');
        }
        $open = (int) $this->db->scalar("SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id = ? AND status IN ('pending_approval', 'approved')", [$acc['id']])
            + (int) $this->db->scalar("SELECT COUNT(*) FROM bank_transfers WHERE (from_account_id = ? OR to_account_id = ?) AND status IN ('pending_approval', 'approved')", [$acc['id'], $acc['id']]);
        if ($open > 0) {
            throw new DomainRuleException("{$open} transaction(s) or transfer(s) on this account are still awaiting approval or posting.");
        }
    }

    /**
     * Validate input; returns normalised values (+ opening_paise).
     * @param array<string, mixed> $data @param array<string, mixed>|null $acc existing account (number may be left blank to keep it)
     * @return array<string, mixed>
     */
    private function validate(array $data, ?array $acc): array
    {
        foreach (['display_name', 'bank_name', 'branch_name', 'account_holder_name', 'account_number', 'ifsc_code', 'micr_code', 'purpose', 'internal_notes', 'currency'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? ''));
        }
        $data['ifsc_code'] = strtoupper($data['ifsc_code']);
        $data['currency'] = strtoupper($data['currency'] ?: 'INR');
        $data['account_number'] = strtoupper((string) preg_replace('/[\s-]+/', '', $data['account_number']));
        $data['opening_balance'] = str_replace(',', '', trim((string) ($data['opening_balance'] ?? '0'))) ?: '0';
        $data['overdraft_limit'] = str_replace(',', '', trim((string) ($data['overdraft_limit'] ?? '0'))) ?: '0';
        $v = Validator::make($data, [
            'display_name' => 'required|string|max:150', 'bank_name' => 'required|string|min:2|max:150', 'branch_name' => 'nullable|string|max:150',
            'account_holder_name' => 'required|string|min:2|max:200',
            'account_number' => ($acc === null ? 'required' : 'nullable') . '|regex:/^[A-Z0-9]{6,20}$/',
            'ifsc_code' => 'required|regex:/^[A-Z]{4}0[A-Z0-9]{6}$/', 'micr_code' => 'nullable|regex:/^\d{9}$/',
            'account_type_id' => 'required|integer|exists:bank_account_types,id', 'currency' => 'required|in:' . implode(',', array_keys(self::CURRENCIES)),
            'opening_date' => 'nullable|date|before_or_equal:today', 'opening_balance' => 'required|regex:/^\d{1,13}(\.\d{1,2})?$/', 'opening_balance_date' => 'required|date|before_or_equal:today',
            'purpose' => 'nullable|string|max:255', 'internal_notes' => 'nullable|string|max:2000', 'overdraft_limit' => 'required|regex:/^\d{1,13}(\.\d{1,2})?$/',
        ], ['account_number' => 'Account number', 'ifsc_code' => 'IFSC code', 'micr_code' => 'MICR code', 'account_type_id' => 'Account type', 'opening_balance_date' => 'Opening balance effective date']);
        if ($v->fails()) {
            $errors = $v->errors();
            if (isset($errors['account_number'])) {
                $errors['account_number'] = ['Enter 6–20 letters or digits (spaces and hyphens are ignored).'];
            }
            foreach (['opening_balance', 'overdraft_limit'] as $k) {
                if (isset($errors[$k])) {
                    $errors[$k] = ['Enter an amount of zero or more, with at most 2 decimals (e.g. 125000.50).'];
                }
            }
            if (isset($errors['ifsc_code'])) {
                $errors['ifsc_code'] = ['IFSC has 11 characters: 4 letters, a zero, then 6 letters or digits (e.g. HDFC0001234).'];
            }
            throw new ValidationException($errors);
        }
        $out = $v->validated();
        $out['account_type_id'] = (int) $out['account_type_id'];
        $out['allow_overdraft'] = !empty($data['allow_overdraft']) ? 1 : 0;
        $out['overdraft_limit_paise'] = $out['allow_overdraft'] ? Money::parse((string) $data['overdraft_limit']) : 0;
        $out['opening_paise'] = Money::parse((string) $data['opening_balance']);
        if ($out['opening_paise'] < 0) {
            throw ValidationException::withMessage('opening_balance', 'The opening balance cannot be negative. Use an overdraft account and record the drawn amount as an adjustment.');
        }
        if ($out['allow_overdraft'] && $out['overdraft_limit_paise'] <= 0) {
            throw ValidationException::withMessage('overdraft_limit', 'Enter the sanctioned overdraft limit.');
        }
        if ($acc !== null && ($out['account_number'] ?? '') === '') {
            $out['account_number'] = null; // keep the stored number
        }
        return $out;
    }

    /** Columns to store from validated input (number encrypted). @param array<string, mixed> $c @return array<string, mixed> */
    private function storable(array $c): array
    {
        $row = [
            'display_name' => $c['display_name'], 'bank_name' => $c['bank_name'], 'branch_name' => $c['branch_name'] ?: null, 'account_holder_name' => $c['account_holder_name'],
            'ifsc_code' => $c['ifsc_code'], 'micr_code' => $c['micr_code'] ?: null, 'account_type_id' => $c['account_type_id'], 'currency' => $c['currency'],
            'opening_date' => $c['opening_date'] ?: null, 'purpose' => $c['purpose'] ?: null, 'internal_notes' => $c['internal_notes'] ?: null,
            'allow_overdraft' => $c['allow_overdraft'], 'overdraft_limit' => Money::toDb($c['overdraft_limit_paise']),
        ];
        if (($c['account_number'] ?? null) !== null) {
            $row['account_number_encrypted'] = FieldCipher::encrypt($c['account_number']);
            $row['account_number_hash'] = FieldCipher::blindIndex($c['account_number'], 'bank_account_number');
            $row['account_number_last4'] = substr($c['account_number'], -4);
        }
        return $row;
    }

    /** @param array<string, mixed> $c */
    private function duplicateCheck(array $c, ?int $exceptId, bool $confirmed): void
    {
        if (($c['account_number'] ?? null) === null) {
            return;
        }
        $hash = FieldCipher::blindIndex($c['account_number'], 'bank_account_number');
        $same = $this->db->first('SELECT account_code, ifsc_code FROM bank_accounts WHERE account_number_hash = ? AND ifsc_code = ?' . ($exceptId ? ' AND id <> ' . $exceptId : ''), [$hash, $c['ifsc_code']]);
        if ($same !== null) {
            throw ValidationException::withMessage('account_number', "This account number is already recorded at this IFSC as {$same['account_code']}.");
        }
        // The same number at another bank/branch can be a different account: warn, let the user confirm.
        $other = $this->db->first('SELECT account_code, bank_name, ifsc_code FROM bank_accounts WHERE account_number_hash = ?' . ($exceptId ? ' AND id <> ' . $exceptId : ''), [$hash]);
        if ($other !== null && !$confirmed) {
            throw new ValidationException(['account_number' => ["The same account number exists as {$other['account_code']} at {$other['bank_name']} ({$other['ifsc_code']}). If this is a different account, tick the confirmation and save again."], 'duplicate_ok' => ['Confirm that this is a different account.']]);
        }
    }

    private function nextCode(): string
    {
        $max = 0;
        foreach ($this->db->select("SELECT account_code FROM bank_accounts WHERE account_code LIKE 'BNK-%'") as $r) {
            $max = max($max, (int) substr((string) $r['account_code'], 4));
        }
        return 'BNK-' . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Never log the encrypted number or hash; show the last four digits instead. @param array<string, mixed> $row @return array<string, mixed> */
    public function auditView(array $row): array
    {
        $out = [];
        foreach ($row as $k => $v) {
            if (in_array($k, ['account_number_encrypted', 'account_number_hash'], true)) {
                continue;
            }
            if (in_array($k, ['id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'type_name', 'type_code', 'created_by_name', 'updated_by_name', 'lock_version'], true)) {
                continue;
            }
            $out[$k === 'account_number_last4' ? 'account_number' : $k] = $k === 'account_number_last4' ? 'XXXXXX' . $v : $v;
        }
        return $out;
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    private function safe(array $values): array
    {
        return $this->auditView($values);
    }

    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new @param array<string, mixed> $actor */
    public function history(int $id, string $event, ?string $from, ?string $to, ?array $old, ?array $new, ?string $reason, ?string $date, array $actor): void
    {
        $this->db->insert('bank_account_history', ['bank_account_id' => $id, 'event' => $event, 'from_status' => $from, 'to_status' => $to,
            'old_values' => $old === null ? null : json_encode($this->safe($old), JSON_UNESCAPED_UNICODE), 'new_values' => $new === null ? null : json_encode($this->safe($new), JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null, 'effective_date' => $date, 'changed_by' => ($actor['id'] ?? 0) > 0 ? (int) $actor['id'] : null, 'created_at' => now()]);
    }

    /** @return list<array<string, mixed>> */
    public function historyFor(int $id): array
    {
        $rows = $this->db->select('SELECT h.*, u.name AS changed_by_name FROM bank_account_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.bank_account_id = ? ORDER BY h.id DESC', [$id]);
        foreach ($rows as &$r) {
            $r['old_values'] = json_decode((string) $r['old_values'], true);
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $rows;
    }
}
