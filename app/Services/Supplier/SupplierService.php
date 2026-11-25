<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Services\Gst\GstinValidator;
use App\Services\Product\HistoryWriter;
use App\Support\BusinessDate;
use App\Support\Uuid;

/**
 * Supplier master. Draft → (approval) → active ↔ inactive / blocked → archived.
 * GSTIN / PAN / GST-treatment changes, bank-detail changes and archival go through the
 * approval engine (inactive workflows approve at once). GSTINs are checked with the Phase 4
 * validator (structure, state code, PAN segment, check character) — never presented as
 * verified with the GST portal. Suppliers are never deleted.
 */
final class SupplierService
{
    public const SUBJECT = 'supplier_change_request';
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked', 'archived' => 'Archived'];
    public const TRANSITIONS = [
        'active' => [['inactive', 'blocked'], 'activate', 'Activate'],
        'inactive' => [['active'], 'deactivate', 'Deactivate'],
        'blocked' => [['active', 'inactive'], 'deactivate', 'Block'],
    ];
    public const GST_TREATMENTS = ['registered' => 'Registered (regular)', 'composition' => 'Registered (composition)', 'sez' => 'SEZ unit / developer', 'unregistered' => 'Unregistered', 'overseas' => 'Overseas supplier'];
    /** Changed through the GSTIN change workflow once the supplier is no longer a draft. */
    public const GST_FIELDS = ['gstin', 'pan', 'gst_treatment', 'gst_state_id', 'legal_name'];
    public const DIRECT_FIELDS = ['name', 'supplier_type_id', 'contact_person', 'mobile', 'email', 'website', 'country', 'payment_terms', 'credit_period_days', 'default_currency', 'internal_notes'];
    public const WORKFLOWS = ['create' => 'supplier_create', 'gstin' => 'supplier_gstin_change', 'bank' => 'supplier_bank_change', 'archive' => 'supplier_archive'];
    private const TITLES = ['create' => 'New supplier', 'gstin' => 'Supplier GSTIN change', 'bank' => 'Supplier bank details', 'archive' => 'Supplier archival', 'update' => 'Supplier change'];
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
        return $this->db->first('SELECT s.*, t.name AS type_name, st.name AS state_name, st.code AS state_code, cu.name AS created_by_name, uu.name AS updated_by_name
            FROM suppliers s JOIN supplier_types t ON t.id = s.supplier_type_id LEFT JOIN gst_states st ON st.id = s.gst_state_id
            LEFT JOIN users cu ON cu.id = s.created_by LEFT JOIN users uu ON uu.id = s.updated_by WHERE s.id = ?', [$id]);
    }

    /**
     * @param array<string, mixed> $f q, status, type, gst, activity, product, state, treatment, archived, linked
     * @param array{0: string, 1: list<mixed>} $scope constraint on alias s
     * @return array{0: string, 1: list<mixed>}
     */
    public function filterSql(array $f, array $scope): array
    {
        [$where, $params] = $scope;
        $sql = [$where];
        $status = (string) ($f['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $sql[] = 's.status = ?';
            $params[] = $status;
        } elseif (($f['archived'] ?? '') === 'only') {
            $sql[] = "s.status = 'archived'";
        } elseif (($f['archived'] ?? '') !== 'with') {
            $sql[] = "s.status <> 'archived'";
        }
        if (($q = trim((string) ($f['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $sql[] = '(LOWER(s.name) LIKE ? OR LOWER(s.legal_name) LIKE ? OR LOWER(s.supplier_code) LIKE ? OR LOWER(s.gstin) LIKE ? OR LOWER(s.contact_person) LIKE ? OR LOWER(s.email) LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like, $like);
        }
        foreach (['type' => 's.supplier_type_id = ?', 'state' => 's.gst_state_id = ?',
            'gst' => "s.id IN (SELECT supplier_id FROM supplier_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)",
            'activity' => "s.id IN (SELECT supplier_id FROM supplier_business_mappings WHERE status = 'active' AND business_activity_id = ?)",
            'product' => "s.id IN (SELECT supplier_id FROM product_supplier_mappings WHERE status = 'active' AND product_id = ?)"] as $k => $cond) {
            if ((int) ($f[$k] ?? 0) > 0) {
                $sql[] = $cond;
                $params[] = (int) $f[$k];
            }
        }
        if (isset(self::GST_TREATMENTS[(string) ($f['treatment'] ?? '')])) {
            $sql[] = 's.gst_treatment = ?';
            $params[] = (string) $f['treatment'];
        }
        if (($f['linked'] ?? '') === 'yes') {
            $sql[] = "s.id IN (SELECT supplier_id FROM product_supplier_mappings WHERE status = 'active')";
        } elseif (($f['linked'] ?? '') === 'no') {
            $sql[] = "s.id NOT IN (SELECT supplier_id FROM product_supplier_mappings WHERE status = 'active')";
        }
        return [implode(' AND ', $sql), $params];
    }

    /** @param array<string, mixed> $f @param array{0: string, 1: list<mixed>} $scope */
    public function paginate(array $f, int $page, int $perPage, array $scope): Paginator
    {
        [$w, $p] = $this->filterSql($f, $scope);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM suppliers s WHERE {$w}", $p);
        $order = match ((string) ($f['sort'] ?? '')) { 'code' => 's.supplier_code', 'status' => 's.status, s.name', 'updated' => 's.updated_at DESC', 'created' => 's.created_at DESC', default => 's.name' };
        $rows = $this->db->select("SELECT s.*, t.name AS type_name, st.name AS state_name,
                (SELECT COUNT(DISTINCT m.product_id) FROM product_supplier_mappings m WHERE m.supplier_id = s.id AND m.status = 'active') AS product_count
            FROM suppliers s JOIN supplier_types t ON t.id = s.supplier_type_id LEFT JOIN gst_states st ON st.id = s.gst_state_id
            WHERE {$w} ORDER BY {$order} LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $p);
        return new Paginator($rows, $total, $page, $perPage);
    }

    /** @return list<array<string, mixed>> */
    public function types(bool $activeOnly = true): array
    {
        return $this->db->select('SELECT t.*, (SELECT COUNT(*) FROM suppliers s WHERE s.supplier_type_id = t.id) AS supplier_count FROM supplier_types t'
            . ($activeOnly ? ' WHERE t.is_active = 1' : '') . ' ORDER BY t.sort_order, t.name');
    }

    /** @return list<array<string, mixed>> */
    public function states(): array
    {
        return $this->db->select('SELECT id, code, name FROM gst_states WHERE allows_registration = 1 ORDER BY sort_order, name');
    }

    /** @return list<array<string, mixed>> */
    public function changes(int $supplierId): array
    {
        $rows = $this->db->select('SELECT c.*, u.name AS requested_by_name FROM supplier_change_requests c LEFT JOIN users u ON u.id = c.requested_by WHERE c.supplier_id = ? ORDER BY c.id DESC', [$supplierId]);
        foreach ($rows as &$r) {
            $r['old_values'] = json_decode((string) $r['old_values'], true);
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function change(int $id): ?array
    {
        $r = $this->db->first('SELECT c.*, s.supplier_code, s.name AS supplier_name FROM supplier_change_requests c JOIN suppliers s ON s.id = c.supplier_id WHERE c.id = ?', [$id]);
        if ($r !== null) {
            $r['old_values'] = json_decode((string) $r['old_values'], true);
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $r;
    }

    /** @return array<string, mixed>|null */
    public function pendingOfType(int $supplierId, string $type): ?array
    {
        return $this->db->first("SELECT * FROM supplier_change_requests WHERE supplier_id = ? AND change_type = ? AND status = 'pending' ORDER BY id DESC LIMIT 1", [$supplierId, $type]);
    }

    /** @return list<array<string, mixed>> */
    public function historyFor(int $supplierId): array
    {
        return HistoryWriter::read($this->db, 'supplier_history', 'supplier_id', $supplierId);
    }

    // ---------------------------------------------------------------- create / edit

    /**
     * @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{0: int, 1: array<string, mixed>|null}
     */
    public function create(array $data, array $actor, bool $submit = false): array
    {
        $clean = $this->validate($data, null);
        $this->duplicateCheck($clean, null, !empty($data['duplicate_ok']));
        $id = $this->db->transaction(function (Database $db) use ($clean, $actor): int {
            $row = $clean + ['uuid' => Uuid::v4(), 'supplier_code' => $this->nextCode(), 'status' => 'draft',
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()];
            $id = $db->insert('suppliers', $row);
            $this->history($id, 'created', null, 'draft', null, $this->auditView($row), null, $actor);
            return $id;
        });
        $s = $this->find($id);
        App::activity()->log('supplier.created', "Added supplier {$s['supplier_code']} ({$s['name']})", 'supplier', $id, module: 'suppliers', newValues: $this->auditView($s));
        return [$id, $submit ? $this->submit($s, $actor) : null];
    }

    /**
     * Drafts change directly. Other suppliers: contact and commercial details at once; GSTIN,
     * PAN, GST treatment and legal name through the GSTIN change workflow.
     * @param array<string, mixed> $s @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{direct: list<string>, key: list<string>, change: array<string, mixed>|null}
     */
    public function update(array $s, array $data, array $actor, ?string $reason = null): array
    {
        $this->assertWritable($s);
        if ($s['status'] === 'pending_approval') {
            throw new DomainRuleException('This supplier is waiting for approval. Decide or withdraw the request first.');
        }
        $clean = $this->validate($data, $s);
        $this->duplicateCheck($clean, (int) $s['id'], !empty($data['duplicate_ok']), $s);
        $changed = [];
        foreach ($clean as $k => $v) {
            if ((string) ($s[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = $v;
            }
        }
        $gst = [];
        $direct = $changed;
        if ($s['status'] !== 'draft') {
            foreach ($changed as $k => $v) {
                if (in_array($k, self::GST_FIELDS, true)) {
                    $gst[$k] = $v;
                    unset($direct[$k]);
                }
            }
        }
        if ($gst !== [] && !App::gate()->allows($actor, 'suppliers.manage_gst_details')) {
            throw new \App\Exceptions\AuthorizationException('suppliers.manage_gst_details', 'You may not change GSTIN, PAN, GST treatment or legal name.');
        }
        if ($direct !== []) {
            $this->db->update('suppliers', $direct + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $s['id']]);
            $o = $this->auditView(array_intersect_key($s, $direct));
            $n = $this->auditView($direct);
            $this->history((int) $s['id'], 'updated', null, null, $o, $n, $reason, $actor);
            App::activity()->log('supplier.updated', "Updated supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers', oldValues: $o, newValues: $n);
        }
        $change = null;
        if ($gst !== []) {
            if (trim((string) $reason) === '') {
                throw ValidationException::withMessage('change_reason', 'Give a reason for changing GST details.');
            }
            if ($this->pendingOfType((int) $s['id'], 'gstin') !== null) {
                throw new DomainRuleException('A GST-detail change is already waiting for approval.');
            }
            $change = $this->request($s, 'gstin', $this->auditView(array_intersect_key($s, $gst)), $gst, $reason, $actor, 'suppliers.manage_gst_details');
        }
        return ['direct' => array_keys($direct), 'key' => array_keys($gst), 'change' => $change];
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $actor */
    public function submit(array $s, array $actor): array
    {
        if ($s['status'] !== 'draft') {
            throw new DomainRuleException('Only draft suppliers can be submitted.');
        }
        $this->db->update('suppliers', ['status' => 'pending_approval', 'rejection_reason' => null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $s['id']]);
        $this->history((int) $s['id'], 'status', 'draft', 'pending_approval', null, null, 'Submitted for approval', $actor);
        try {
            return $this->request($s, 'create', null, ['status' => 'active'], 'New supplier', $actor, 'suppliers.create');
        } catch (\Throwable $e) {
            $this->db->update('suppliers', ['status' => 'draft'], ['id' => $s['id']]);
            throw $e;
        }
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $actor */
    public function changeStatus(array $s, string $to, ?string $reason, array $actor): void
    {
        $this->assertWritable($s);
        if (!isset(self::TRANSITIONS[$to])) {
            throw ValidationException::withMessage('status', 'Choose a valid status.');
        }
        [$from, , $label] = self::TRANSITIONS[$to];
        if (!in_array($s['status'], $from, true)) {
            throw new DomainRuleException('A ' . strtolower(self::STATUSES[$s['status']] ?? $s['status']) . ' supplier cannot be changed to ' . strtolower(self::STATUSES[$to]) . '.');
        }
        $reason = trim((string) $reason);
        if ($to !== 'active' && $reason === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('suppliers', ['status' => $to, 'status_reason' => $reason ?: null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $s['id']]);
        $this->history((int) $s['id'], 'status', $s['status'], $to, null, null, $reason ?: null, $actor);
        App::activity()->log('supplier.status_changed', "{$label}: supplier {$s['supplier_code']} ({$s['status']} → {$to})", 'supplier', (int) $s['id'], module: 'suppliers',
            oldValues: ['status' => $s['status']], newValues: ['status' => $to, 'reason' => $reason ?: null]);
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $actor */
    public function requestArchive(array $s, string $reason, array $actor): array
    {
        if ($s['status'] === 'archived') {
            throw new DomainRuleException('Already archived.');
        }
        if ($s['status'] === 'pending_approval') {
            throw new DomainRuleException('Decide or withdraw the pending approval first.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($this->pendingOfType((int) $s['id'], 'archive') !== null) {
            throw new DomainRuleException('Archiving is already waiting for approval.');
        }
        return $this->request($s, 'archive', ['status' => $s['status']], ['status' => 'archived'], $reason, $actor, 'suppliers.archive');
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $actor */
    public function restore(array $s, array $actor): void
    {
        if ($s['status'] !== 'archived') {
            throw new DomainRuleException('This supplier is not archived.');
        }
        $to = (string) ($s['status_before_archive'] ?: 'inactive');
        if ($to === 'pending_approval') {
            $to = 'draft';
        }
        $this->db->update('suppliers', ['status' => $to, 'status_before_archive' => null, 'archived_at' => null, 'archived_by' => null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $s['id']]);
        $this->history((int) $s['id'], 'restored', 'archived', $to, null, null, null, $actor);
        App::activity()->log('supplier.restored', "Restored supplier {$s['supplier_code']} (now {$to})", 'supplier', (int) $s['id'], module: 'suppliers', newValues: ['status' => $to]);
    }

    /** For later phases (purchases): may this supplier be used for a new transaction? @param array<string, mixed> $s */
    public static function assertUsable(array $s): void
    {
        if ($s['status'] !== 'active') {
            throw new DomainRuleException("{$s['name']} is " . strtolower(self::STATUSES[$s['status']] ?? $s['status']) . ' and cannot be used in new transactions.');
        }
    }

    /** @param array<string, mixed> $s */
    public function assertWritable(array $s): void
    {
        if ($s['status'] === 'archived') {
            throw new DomainRuleException('Archived suppliers are read-only. Restore the supplier first.');
        }
    }

    /**
     * Live GSTIN check for the form: structure, state, PAN segment and check character, plus
     * the duplicate rule. @return array<string, mixed>
     */
    public function checkGstin(string $gstin, ?string $pan, ?int $exceptId): array
    {
        $r = (new GstinValidator($this->db))->validate($gstin, null, $pan);
        $dup = $r['gstin'] !== '' ? $this->db->first('SELECT id, supplier_code, name FROM suppliers WHERE gstin = ? AND id <> ?', [$r['gstin'], $exceptId ?? 0]) : null;
        return ['valid' => $r['valid'], 'gstin' => $r['gstin'], 'errors' => $r['errors'], 'state' => $r['state']['name'] ?? null, 'state_id' => isset($r['state']['id']) ? (int) $r['state']['id'] : null,
            'pan' => $r['pan'], 'duplicate' => $dup !== null ? $dup['supplier_code'] . ' ' . $dup['name'] : null, 'duplicate_blocks' => $dup !== null && setting('supplier_gstin_unique', true)];
    }

    // ---------------------------------------------------------------- approvals

    /**
     * @param array<string, mixed> $s @param array<string, mixed>|null $old @param array<string, mixed> $new @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function request(array $s, string $type, ?array $old, array $new, ?string $reason, array $actor, string $permission, ?int $bankDetailId = null): array
    {
        $id = $this->db->insert('supplier_change_requests', [
            'supplier_id' => (int) $s['id'], 'bank_detail_id' => $bankDetailId, 'change_type' => $type,
            'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE), 'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null, 'status' => 'pending', 'requested_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        App::activity()->log('supplier.change_requested', 'Requested ' . strtolower(self::TITLES[$type]) . " for supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers',
            oldValues: $old, newValues: $new + ['request' => $id, 'reason' => $reason]);
        try {
            $req = App::approvals()->submit(self::WORKFLOWS[$type], self::SUBJECT, $id, 0.0, self::TITLES[$type] . ": {$s['supplier_code']} — {$s['name']}", $actor, $permission);
        } catch (\Throwable $e) {
            $this->db->update('supplier_change_requests', ['status' => 'cancelled', 'decided_at' => now()], ['id' => $id]);
            throw $e;
        }
        $this->db->execute('UPDATE supplier_change_requests SET approval_request_id = ? WHERE id = ? AND approval_request_id IS NULL', [$req['id'], $id]);
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
        $s = $this->find((int) $change['supplier_id']);
        $new = match ($status) { 'approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled', default => null };
        if ($new === null || $s === null) {
            return;
        }
        $this->db->transaction(function () use ($change, $s, $new, $by, $last, $request): void {
            $this->db->update('supplier_change_requests', ['status' => $new, 'approval_request_id' => (int) $request['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $change['id']]);
            match ($change['change_type']) {
                'create' => $this->decideCreate($s, $new, $last['comment'] ?? null, $by),
                'gstin' => $new === 'approved' ? $this->applyGst($s, $change, $by) : null,
                'bank' => (new SupplierBankService($this->db))->decide($s, $change, $new, $last['comment'] ?? null, $by),
                'archive' => $new === 'approved' ? $this->applyArchive($s, (string) $change['reason'], $by) : null,
                default => null,
            };
        });
        App::activity()->log('supplier.change_' . $new, ucfirst($new) . ' ' . strtolower(self::TITLES[$change['change_type']]) . " for supplier {$s['supplier_code']}", 'supplier', (int) $s['id'],
            module: 'suppliers', newValues: ['request' => (int) $change['id'], 'status' => $new], userId: $by['id']);
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed> $s @param array<string, mixed> $by */
    private function decideCreate(array $s, string $status, ?string $comment, array $by): void
    {
        if ($s['status'] !== 'pending_approval') {
            return;
        }
        $to = $status === 'approved' ? 'active' : 'draft';
        $this->db->update('suppliers', ['status' => $to, 'rejection_reason' => $status === 'rejected' ? $comment : null, 'updated_at' => now()], ['id' => $s['id']]);
        $this->history((int) $s['id'], 'status', 'pending_approval', $to, null, null, $status === 'rejected' ? 'Rejected: ' . ($comment ?? '') : ($status === 'cancelled' ? 'Request withdrawn' : 'Approved'), $by);
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $change @param array<string, mixed> $by */
    private function applyGst(array $s, array $change, array $by): void
    {
        $new = array_intersect_key($change['new_values'], array_flip(self::GST_FIELDS));
        if (!empty($new['gstin'])) {
            $clash = $this->db->scalar('SELECT supplier_code FROM suppliers WHERE gstin = ? AND id <> ?', [$new['gstin'], $s['id']]);
            if ($clash && setting('supplier_gstin_unique', true)) {
                throw new DomainRuleException("Supplier {$clash} already has GSTIN {$new['gstin']}.");
            }
        }
        $this->db->update('suppliers', $new + ['updated_by' => $by['id'], 'updated_at' => now()], ['id' => $s['id']]);
        $this->history((int) $s['id'], 'gst_details_changed', null, null, $change['old_values'], $this->auditView($new), $change['reason'], $by);
    }

    /** @param array<string, mixed> $s @param array<string, mixed> $by */
    private function applyArchive(array $s, string $reason, array $by): void
    {
        if ($s['status'] === 'archived') {
            return;
        }
        $this->db->update('suppliers', ['status' => 'archived', 'status_before_archive' => $s['status'], 'status_reason' => mb_substr($reason, 0, 255), 'archived_at' => now(), 'archived_by' => $by['id'], 'updated_at' => now()], ['id' => $s['id']]);
        $this->history((int) $s['id'], 'archived', $s['status'], 'archived', null, null, $reason, $by);
    }

    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new @param array<string, mixed> $actor */
    private function history(int $id, string $event, ?string $from, ?string $to, ?array $old, ?array $new, ?string $reason, array $actor): void
    {
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', $id, $event, $from, $to, $old, $new, $reason, BusinessDate::today(), $actor);
    }

    /**
     * @param array<string, mixed> $data @param array<string, mixed>|null $s
     * @return array<string, mixed>
     */
    private function validate(array $data, ?array $s): array
    {
        foreach (['name', 'legal_name', 'contact_person', 'mobile', 'email', 'website', 'pan', 'gstin', 'payment_terms', 'internal_notes', 'country', 'default_currency'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? ''));
        }
        $data['name'] = (string) preg_replace('/\s+/', ' ', $data['name']);
        $data['legal_name'] = (string) preg_replace('/\s+/', ' ', $data['legal_name'] !== '' ? $data['legal_name'] : $data['name']);
        $data['pan'] = strtoupper($data['pan']);
        $data['gstin'] = GstinValidator::normalize($data['gstin']);
        $data['country'] = strtoupper($data['country'] ?: 'IN');
        $data['default_currency'] = strtoupper($data['default_currency'] ?: 'INR');
        $data['mobile'] = (string) preg_replace('/[\s()-]+/', '', $data['mobile']);
        $treatment = (string) ($data['gst_treatment'] ?? 'registered');
        $v = Validator::make($data, [
            'name' => 'required|string|min:2|max:200', 'legal_name' => 'required|string|min:2|max:200', 'supplier_type_id' => 'required|integer|exists:supplier_types,id',
            'contact_person' => 'nullable|string|max:150', 'mobile' => 'nullable|regex:/^\+?\d{8,15}$/', 'email' => 'nullable|email|max:150', 'website' => 'nullable|string|max:255',
            'pan' => 'nullable|regex:/^[A-Z]{5}\d{4}[A-Z]$/', 'country' => 'required|regex:/^[A-Z]{2}$/', 'payment_terms' => 'nullable|string|max:100',
            'credit_period_days' => 'nullable|integer|min:0|max:365', 'default_currency' => 'required|in:' . implode(',', array_keys(\App\Services\Bank\BankAccountService::CURRENCIES)),
            'internal_notes' => 'nullable|string|max:5000',
        ], ['supplier_type_id' => 'Supplier type', 'pan' => 'PAN', 'credit_period_days' => 'Credit period', 'default_currency' => 'Currency']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if (!isset(self::GST_TREATMENTS[$treatment])) {
            throw ValidationException::withMessage('gst_treatment', 'Choose the GST treatment.');
        }
        if ($data['website'] !== '' && (filter_var($data['website'], FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $data['website']))) {
            throw ValidationException::withMessage('website', 'Enter a full web address starting with https://');
        }
        if ($data['country'] === 'IN' && $data['mobile'] !== '' && preg_match('/^(\+91)?[6-9]\d{9}$/', $data['mobile']) !== 1) {
            throw ValidationException::withMessage('mobile', 'Enter a 10-digit Indian mobile number (optionally with +91).');
        }
        if ($data['pan'] !== '' && !GstinValidator::isPan($data['pan'])) {
            throw ValidationException::withMessage('pan', 'This PAN is not valid: 5 letters (the 4th shows the holder type), 4 digits and a letter.');
        }
        $stateId = null;
        if (in_array($treatment, ['registered', 'composition', 'sez'], true)) {
            if ($data['gstin'] === '') {
                throw ValidationException::withMessage('gstin', 'A registered supplier needs a GSTIN. Choose "Unregistered" if it has none.');
            }
            $r = (new GstinValidator($this->db))->validate($data['gstin'], null, $data['pan'] !== '' ? $data['pan'] : null, $s !== null && $s['gstin'] === $data['gstin']);
            if (!$r['valid']) {
                throw ValidationException::withMessage('gstin', implode(' ', $r['errors']));
            }
            $stateId = (int) $r['state']['id'];
            if ($data['pan'] === '') {
                $data['pan'] = (string) $r['pan'];
            }
            if ($data['country'] !== 'IN') {
                throw ValidationException::withMessage('country', 'A supplier with a GSTIN is registered in India (country IN).');
            }
        } elseif ($data['gstin'] !== '') {
            throw ValidationException::withMessage('gstin', 'Leave the GSTIN empty for ' . strtolower(self::GST_TREATMENTS[$treatment]) . ' suppliers, or change the GST treatment.');
        }
        if ($treatment === 'overseas' && $data['country'] === 'IN') {
            throw ValidationException::withMessage('country', 'An overseas supplier has a country other than India.');
        }
        $n = static fn (string $k) => $data[$k] !== '' ? $data[$k] : null;
        return [
            'name' => $data['name'], 'legal_name' => $data['legal_name'], 'supplier_type_id' => (int) $data['supplier_type_id'], 'contact_person' => $n('contact_person'),
            'mobile' => $n('mobile'), 'email' => $data['email'] !== '' ? mb_strtolower($data['email']) : null, 'website' => $n('website'), 'pan' => $n('pan'), 'gstin' => $n('gstin'),
            'gst_treatment' => $treatment, 'gst_state_id' => $stateId, 'country' => $data['country'], 'payment_terms' => $n('payment_terms'),
            'credit_period_days' => ($data['credit_period_days'] ?? '') !== '' && $data['credit_period_days'] !== null ? (int) $data['credit_period_days'] : null,
            'default_currency' => $data['default_currency'], 'internal_notes' => $n('internal_notes'),
        ];
    }

    /**
     * Same GSTIN: refused when one-supplier-per-GSTIN is on, otherwise needs confirmation.
     * Same PAN (different GSTIN — e.g. another state branch) always needs confirmation.
     * @param array<string, mixed> $clean
     */
    private function duplicateCheck(array $clean, ?int $exceptId, bool $confirmed, ?array $existing = null): void
    {
        // Only a new or changed GSTIN / PAN is checked, so a confirmed duplicate is not asked about on every save.
        if ($existing !== null && (string) $existing['gstin'] === (string) $clean['gstin']) {
            $clean['gstin'] = null;
        }
        if ($existing !== null && (string) $existing['pan'] === (string) $clean['pan']) {
            $clean['pan'] = null;
        }
        if ($clean['gstin'] !== null) {
            $dup = $this->db->first('SELECT supplier_code, name FROM suppliers WHERE gstin = ? AND id <> ?', [$clean['gstin'], $exceptId ?? 0]);
            if ($dup !== null && (setting('supplier_gstin_unique', true) || !$confirmed)) {
                throw ValidationException::withMessage(setting('supplier_gstin_unique', true) ? 'gstin' : 'duplicate_ok', "Supplier {$dup['supplier_code']} ({$dup['name']}) already has this GSTIN."
                    . (setting('supplier_gstin_unique', true) ? '' : ' Tick the confirmation to save it for another supplier anyway.'));
            }
        }
        if ($clean['pan'] !== null && !$confirmed) {
            $dup = $this->db->first('SELECT supplier_code, name FROM suppliers WHERE pan = ? AND id <> ? AND (gstin IS NULL OR gstin <> ?)', [$clean['pan'], $exceptId ?? 0, (string) $clean['gstin']]);
            if ($dup !== null) {
                throw ValidationException::withMessage('duplicate_ok', "Supplier {$dup['supplier_code']} ({$dup['name']}) has the same PAN. If this is another registration of the same business, tick the confirmation and save again.");
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public function auditView(array $row): array
    {
        return array_intersect_key($row, array_flip(array_merge(self::GST_FIELDS, self::DIRECT_FIELDS, ['supplier_code', 'status'])));
    }

    private function nextCode(): string
    {
        $max = 0;
        foreach ($this->db->select("SELECT supplier_code FROM suppliers WHERE supplier_code LIKE 'SUP-%'") as $r) {
            $max = max($max, (int) substr((string) $r['supplier_code'], 4));
        }
        return 'SUP-' . str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveType(array $data, array $actor, ?int $id = null): int
    {
        $existing = $id !== null ? ($this->db->first('SELECT * FROM supplier_types WHERE id = ?', [$id]) ?? throw new DomainRuleException('Supplier type not found.')) : null;
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        if (preg_match('/^[a-z0-9_]{2,40}$/', $code) !== 1) {
            throw ValidationException::withMessage('code', 'Use 2–40 lower-case letters, digits or underscores.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw ValidationException::withMessage('name', 'Enter a name of up to 100 characters.');
        }
        if ($existing !== null && (int) $existing['is_system'] === 1 && $code !== $existing['code']) {
            throw ValidationException::withMessage('code', 'Built-in types keep their code.');
        }
        if ($this->db->scalar('SELECT 1 FROM supplier_types WHERE code = ?' . ($id ? ' AND id <> ?' : ''), array_merge([$code], $id ? [$id] : []))) {
            throw ValidationException::withMessage('code', 'This code is already used.');
        }
        $row = ['code' => $code, 'name' => $name, 'description' => trim((string) ($data['description'] ?? '')) ?: null, 'is_active' => !empty($data['is_active']) || $existing === null ? 1 : 0,
            'sort_order' => max(0, min(9999, (int) ($data['sort_order'] ?? 0))), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($existing === null) {
            $id = $this->db->insert('supplier_types', $row + ['created_by' => (int) $actor['id'], 'created_at' => now()]);
            App::activity()->log('supplier.type_created', "Added supplier type {$name}", 'supplier_type', $id, module: 'suppliers', newValues: $row);
            return $id;
        }
        $this->db->update('supplier_types', $row, ['id' => $id]);
        App::activity()->log('supplier.type_updated', "Updated supplier type {$name}", 'supplier_type', (int) $id, module: 'suppliers', oldValues: array_intersect_key($existing, $row), newValues: $row);
        return (int) $id;
    }
}
