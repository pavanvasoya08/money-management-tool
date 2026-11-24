<?php

declare(strict_types=1);

namespace App\Services\Gst;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;

/**
 * GST registrations: master data only (no tax calculation, filing or portal access).
 *
 * Sensitive operations go through CHANGE REQUESTS submitted to the existing
 * approval engine — one code path whether or not a workflow is active:
 *   create  → workflow gst_registration_create  (permission gst_registrations.create)
 *   update  → workflow gst_registration_update  for KEY fields only (gst_registrations.edit)
 *   cancel  → workflow gst_registration_cancel  (gst_registrations.cancel)
 * An inactive workflow approves automatically and the change is applied at once;
 * an active one applies the change when the last step approves. Old and new
 * values are kept on the change request and in the audit log.
 *
 * Registrations are never deleted: they are deactivated, suspended, cancelled
 * and/or archived. Every change is audited against subject "gst_registration".
 */
final class GstRegistrationService
{
    public const SUBJECT = 'gst_registration_change';
    public const STATUSES = [
        'pending_verification' => 'Pending verification', 'active' => 'Active', 'inactive' => 'Inactive',
        'suspended' => 'Suspended', 'cancelled' => 'Cancelled',
    ];
    /** Statuses a user may choose when adding a registration. */
    public const INITIAL_STATUSES = ['pending_verification', 'active', 'inactive'];
    /** target status => [permission action, allowed source statuses] */
    public const TRANSITIONS = [
        'active' => ['activate', ['pending_verification', 'inactive', 'suspended']],
        'inactive' => ['deactivate', ['pending_verification', 'active', 'suspended']],
        'suspended' => ['suspend', ['active', 'inactive']],
        'cancelled' => ['cancel', ['pending_verification', 'active', 'inactive', 'suspended']],
    ];
    /** Changes to these fields are "key detail" changes and use the update workflow. */
    public const KEY_FIELDS = ['gstin', 'legal_name', 'pan', 'registration_type_id', 'registration_date', 'effective_from'];
    public const DIRECT_FIELDS = ['trade_name', 'business_constitution', 'taxpayer_category', 'nature_of_business', 'validity_end_date',
        'contact_person', 'contact_phone', 'contact_email', 'portal_reference', 'internal_notes'];
    public const CONSTITUTIONS = [
        'Proprietorship', 'Partnership firm', 'Limited Liability Partnership (LLP)', 'Private limited company', 'Public limited company',
        'One Person Company', 'Hindu Undivided Family (HUF)', 'Trust', 'Society / club', 'Government department or body', 'Local authority', 'Other',
    ];
    public const TAXPAYER_CATEGORIES = ['Normal taxpayer', 'Composition taxpayer', 'SEZ unit', 'SEZ developer', 'E-commerce operator', 'Input Service Distributor', 'Tax deductor', 'Non-resident', 'Other'];
    private const WORKFLOWS = ['create' => 'gst_registration_create', 'update' => 'gst_registration_update', 'cancel' => 'gst_registration_cancel'];
    private static bool $listening = false;

    public function __construct(private readonly Database $db)
    {
    }

    /** Wire approval decisions back to registrations (called once when the approval engine is created). */
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

    // ------------------------------------------------------------------ queries

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT g.*, s.code AS state_code, s.name AS state_name, t.name AS type_name, t.code AS type_code, t.has_validity_period,
                    cu.name AS created_by_name, uu.name AS updated_by_name, au.name AS archived_by_name
             FROM gst_registrations g JOIN gst_states s ON s.id = g.state_id JOIN gst_registration_types t ON t.id = g.registration_type_id
             LEFT JOIN users cu ON cu.id = g.created_by LEFT JOIN users uu ON uu.id = g.updated_by LEFT JOIN users au ON au.id = g.archived_by
             WHERE g.id = ?',
            [$id]
        );
    }

    /**
     * @param array<string, mixed> $filters q, gstin, legal_name, trade_name, partner, state, type, status, archived, from, to, sort
     * @param array{0: string, 1: list<mixed>} $scope record scope for alias g
     */
    public function paginate(array $filters, int $page, int $perPage, array $scope): Paginator
    {
        [$where, $params] = $this->filterSql($filters, $scope);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM gst_registrations g WHERE {$where}", $params);
        $page = max(1, min($page, max(1, (int) ceil($total / max(1, $perPage)))));
        $rows = $this->db->select(
            "SELECT g.*, s.code AS state_code, s.name AS state_name, t.name AS type_name,
                    (SELECT COUNT(*) FROM gst_registration_partner_mappings m WHERE m.gst_registration_id = g.id AND m.status = 'active') AS partner_count
             FROM gst_registrations g JOIN gst_states s ON s.id = g.state_id JOIN gst_registration_types t ON t.id = g.registration_type_id
             WHERE {$where} ORDER BY " . self::orderBy((string) ($filters['sort'] ?? '')) . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (($page - 1) * $perPage),
            $params
        );
        return new Paginator($rows, $total, $page, $perPage);
    }

    /**
     * @param array<string, mixed> $filters @param array{0: string, 1: list<mixed>} $scope
     * @return array{0: string, 1: list<mixed>}
     */
    public function filterSql(array $filters, array $scope): array
    {
        $where = [$scope[0]];
        $params = $scope[1];
        $archived = (string) ($filters['archived'] ?? '');
        if ($archived === 'only') {
            $where[] = 'g.is_archived = 1';
        } elseif ($archived !== 'with') {
            $where[] = 'g.is_archived = 0';
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = Database::likeContains(mb_strtolower($q));
            $where[] = "(LOWER(g.gstin) LIKE ? ESCAPE '!' OR LOWER(g.legal_name) LIKE ? ESCAPE '!' OR LOWER(COALESCE(g.trade_name, '')) LIKE ? ESCAPE '!')";
            array_push($params, $like, $like, $like);
        }
        foreach (['gstin' => 'g.gstin', 'legal_name' => 'g.legal_name', 'trade_name' => 'g.trade_name'] as $key => $col) {
            if (($v = trim((string) ($filters[$key] ?? ''))) !== '') {
                $where[] = "LOWER(COALESCE({$col}, '')) LIKE ? ESCAPE '!'";
                $params[] = Database::likeContains(mb_strtolower($key === 'gstin' ? GstinValidator::normalize($v) : $v));
            }
        }
        if ((int) ($filters['partner'] ?? 0) > 0) {
            $where[] = "g.id IN (SELECT gst_registration_id FROM gst_registration_partner_mappings WHERE partner_id = ? AND status = 'active')";
            $params[] = (int) $filters['partner'];
        }
        if ((int) ($filters['state'] ?? 0) > 0) {
            $where[] = 'g.state_id = ?';
            $params[] = (int) $filters['state'];
        }
        if ((int) ($filters['type'] ?? 0) > 0) {
            $where[] = 'g.registration_type_id = ?';
            $params[] = (int) $filters['type'];
        }
        $status = (string) ($filters['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $where[] = 'g.status = ?';
            $params[] = $status;
        } elseif ($status === 'cancelled_suspended') {
            $where[] = "g.status IN ('cancelled', 'suspended')";
        } elseif ($status === 'pending_approval') {
            $where[] = "(g.approval_state = 'pending' OR EXISTS (SELECT 1 FROM gst_registration_change_requests c WHERE c.gst_registration_id = g.id AND c.status = 'pending'))";
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
            if (BusinessDate::isValid((string) ($filters[$key] ?? ''))) {
                $where[] = "g.registration_date {$op} ?";
                $params[] = (string) $filters[$key];
            }
        }
        return [implode(' AND ', $where), $params];
    }

    public static function orderBy(string $sort): string
    {
        return match ($sort) {
            'gstin' => 'g.gstin ASC',
            'legal_name' => 'g.legal_name ASC, g.id ASC',
            'state' => 's.code ASC, g.legal_name ASC',
            'status' => 'g.status ASC, g.legal_name ASC',
            'registration_date' => 'g.registration_date DESC, g.id DESC',
            'updated' => 'g.updated_at DESC, g.id DESC',
            default => 'g.created_at DESC, g.id DESC',
        };
    }

    /**
     * Dashboard figures — all from the database, within the user's record scope.
     * @param array{0: string, 1: list<mixed>} $scope
     * @return array<string, mixed>
     */
    public function dashboard(array $scope): array
    {
        [$w, $p] = $scope;
        $byStatus = array_fill_keys(array_keys(self::STATUSES), 0);
        foreach ($this->db->select("SELECT g.status, COUNT(*) AS n FROM gst_registrations g WHERE {$w} AND g.is_archived = 0 GROUP BY g.status", $p) as $r) {
            $byStatus[$r['status']] = (int) $r['n'];
        }
        $days = max(1, (int) App::settings()->get('gst_document_expiry_warning_days', 30));
        $today = BusinessDate::today();
        $until = BusinessDate::addDays($today, $days);
        $base = "FROM gst_registrations g JOIN gst_states s ON s.id = g.state_id JOIN gst_registration_types t ON t.id = g.registration_type_id WHERE {$w}";
        $cols = 'SELECT g.id, g.gstin, g.legal_name, g.trade_name, g.status, g.created_at, g.updated_at, s.code AS state_code, s.name AS state_name, t.name AS type_name ';
        $attention = [];
        foreach ($this->db->select($cols . ", g.validity_end_date {$base} AND g.is_archived = 0 AND g.status NOT IN ('cancelled') AND g.validity_end_date IS NOT NULL AND g.validity_end_date <= ? ORDER BY g.validity_end_date", array_merge($p, [$until])) as $r) {
            $attention[] = $r + ['issue' => ($r['validity_end_date'] < $today ? 'Registration validity ended on ' : 'Registration validity ends on ') . format_date((string) $r['validity_end_date'])];
        }
        foreach ($this->db->select(
            "SELECT g.id, g.gstin, g.legal_name, g.trade_name, g.status, s.code AS state_code, s.name AS state_name, t.name AS type_name, d.title AS doc_title, d.expiry_date
             FROM gst_registration_documents d JOIN gst_registrations g ON g.id = d.gst_registration_id JOIN gst_states s ON s.id = g.state_id JOIN gst_registration_types t ON t.id = g.registration_type_id
             WHERE {$w} AND g.is_archived = 0 AND d.status = 'active' AND d.expiry_date IS NOT NULL AND d.expiry_date <= ? ORDER BY d.expiry_date",
            array_merge($p, [$until])
        ) as $r) {
            $attention[] = $r + ['issue' => 'Document “' . $r['doc_title'] . '” ' . ($r['expiry_date'] < $today ? 'expired on ' : 'expires on ') . format_date((string) $r['expiry_date'])];
        }
        foreach ($this->db->select($cols . "{$base} AND g.is_archived = 0 AND g.status = 'pending_verification' AND g.approval_state = 'none' ORDER BY g.created_at", $p) as $r) {
            $attention[] = $r + ['issue' => 'Pending verification — check the details against the registration certificate, then activate'];
        }
        foreach ($this->db->select($cols . ", g.approval_state AS att_approval {$base} AND g.is_archived = 0 AND (g.approval_state IN ('pending', 'rejected') OR EXISTS (SELECT 1 FROM gst_registration_change_requests c WHERE c.gst_registration_id = g.id AND c.status = 'pending'))", $p) as $r) {
            $attention[] = $r + ['issue' => $r['att_approval'] === 'rejected' ? 'Change was rejected — review, correct and resubmit'
                : ($r['status'] === 'pending_verification' ? 'New registration waiting for approval' : 'Change waiting for approval')];
        }
        foreach ($this->db->select($cols . "{$base} AND g.is_archived = 0 AND g.status = 'active' AND NOT EXISTS (SELECT 1 FROM gst_registration_documents d WHERE d.gst_registration_id = g.id AND d.status = 'active' AND d.document_type = 'registration_certificate')", $p) as $r) {
            $attention[] = $r + ['issue' => 'No registration certificate uploaded'];
        }

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'archived' => (int) $this->db->scalar("SELECT COUNT(*) FROM gst_registrations g WHERE {$w} AND g.is_archived = 1", $p),
            'attention' => array_slice($attention, 0, 25),
            'attention_days' => $days,
            'by_state' => $this->db->select(
                "SELECT s.id, s.code, s.name, COUNT(g.id) AS total, SUM(CASE WHEN g.status = 'active' THEN 1 ELSE 0 END) AS active
                 FROM gst_registrations g JOIN gst_states s ON s.id = g.state_id WHERE {$w} AND g.is_archived = 0 GROUP BY s.id, s.code, s.name ORDER BY total DESC, s.code",
                $p
            ),
            'by_partner' => $this->db->select(
                "SELECT pa.id, pa.partner_code, pa.name, COUNT(DISTINCT g.id) AS total, SUM(CASE WHEN g.status = 'active' THEN 1 ELSE 0 END) AS active
                 FROM gst_registration_partner_mappings m JOIN partners pa ON pa.id = m.partner_id JOIN gst_registrations g ON g.id = m.gst_registration_id
                 WHERE {$w} AND m.status = 'active' AND g.is_archived = 0 GROUP BY pa.id, pa.partner_code, pa.name ORDER BY total DESC, pa.name",
                $p
            ),
            'unmapped' => (int) $this->db->scalar("SELECT COUNT(*) FROM gst_registrations g WHERE {$w} AND g.is_archived = 0 AND NOT EXISTS (SELECT 1 FROM gst_registration_partner_mappings m WHERE m.gst_registration_id = g.id AND m.status = 'active')", $p),
            'recent_added' => $this->db->select($cols . "{$base} ORDER BY g.created_at DESC, g.id DESC LIMIT 6", $p),
            'recent_updated' => $this->db->select($cols . "{$base} ORDER BY g.updated_at DESC, g.id DESC LIMIT 6", $p),
        ];
    }

    /** Pending change request of a registration, if any. @return array<string, mixed>|null */
    public function pendingChange(int $id): ?array
    {
        $c = $this->db->first("SELECT c.*, u.name AS requested_by_name FROM gst_registration_change_requests c LEFT JOIN users u ON u.id = c.requested_by WHERE c.gst_registration_id = ? AND c.status = 'pending' ORDER BY c.id DESC", [$id]);
        return $c === null ? null : self::decodeChange($c);
    }

    /** @return list<array<string, mixed>> */
    public function changeRequests(int $id): array
    {
        return array_map([self::class, 'decodeChange'], $this->db->select(
            'SELECT c.*, u.name AS requested_by_name FROM gst_registration_change_requests c LEFT JOIN users u ON u.id = c.requested_by WHERE c.gst_registration_id = ? ORDER BY c.id DESC',
            [$id]
        ));
    }

    /** @return array<string, mixed>|null */
    public function change(int $changeId): ?array
    {
        $c = $this->db->first('SELECT c.*, u.name AS requested_by_name FROM gst_registration_change_requests c LEFT JOIN users u ON u.id = c.requested_by WHERE c.id = ?', [$changeId]);
        return $c === null ? null : self::decodeChange($c);
    }

    /** @return list<array<string, mixed>> */
    public function statusHistory(int $id): array
    {
        return $this->db->select(
            'SELECT h.*, u.name AS changed_by_name FROM gst_registration_status_history h LEFT JOIN users u ON u.id = h.changed_by WHERE h.gst_registration_id = ? ORDER BY h.id DESC',
            [$id]
        );
    }

    /** Registration options for selects (within scope). @param array{0: string, 1: list<mixed>} $scope @return list<array<string, mixed>> */
    public function options(array $scope): array
    {
        return $this->db->select("SELECT g.id, g.gstin, g.legal_name, g.status FROM gst_registrations g WHERE {$scope[0]} AND g.is_archived = 0 ORDER BY g.legal_name", $scope[1]);
    }

    // ------------------------------------------------------------------ writing

    /**
     * Add a registration. Returns [id, change request]. The registration starts as
     * "Pending verification" with a pending creation request when a workflow applies.
     * @param array<string, mixed> $data validated input incl. principal address (addr_*)
     * @param array<string, mixed> $actor
     * @return array{0: int, 1: array<string, mixed>}
     */
    public function create(array $data, array $actor): array
    {
        $type = $this->type((int) $data['registration_type_id']);
        $check = $this->checkGstin((string) $data['gstin'], $type, (string) ($data['pan'] ?? ''), null);
        $status = in_array($data['status'] ?? '', self::INITIAL_STATUSES, true) ? (string) $data['status'] : 'pending_verification';
        $this->assertDates($data, $type);
        $principal = $this->principalAddressInput($data);
        $now = now();

        return $this->db->transaction(function () use ($data, $actor, $check, $status, $principal, $now, $type): array {
            $row = [
                'gstin' => $check['gstin'], 'legal_name' => trim((string) $data['legal_name']), 'pan' => $check['pan'],
                'registration_type_id' => (int) $type['id'], 'state_id' => (int) $check['state']['id'], 'status' => 'pending_verification',
                'registration_date' => self::date($data['registration_date'] ?? null), 'effective_from' => self::date($data['effective_from'] ?? null),
                'approval_state' => 'pending', 'is_archived' => 0, 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'],
                'created_at' => $now, 'updated_at' => $now,
            ];
            foreach (self::DIRECT_FIELDS as $f) {
                $row[$f] = self::clean($f, $data[$f] ?? null);
            }
            $id = $this->db->insert('gst_registrations', $row);
            $this->db->insert('gst_registration_addresses', $principal + [
                'gst_registration_id' => $id, 'is_principal' => 1, 'status' => 'active', 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->db->insert('gst_registration_settings', ['gst_registration_id' => $id, 'is_default' => 0, 'default_state_id' => (int) $check['state']['id'],
                'default_place_of_supply_state_id' => (int) $check['state']['id'], 'applicable_registration_type_id' => (int) $type['id'],
                'prices_include_tax' => 0, 'e_invoicing_applicable' => 0, 'e_way_bill_applicable' => 0, 'effective_from' => $row['effective_from'],
                'updated_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
            $this->history($id, null, 'pending_verification', $row['effective_from'], 'Registration added', null, $actor);
            App::activity()->log('gst.registration_created', "Added GST registration {$row['gstin']} ({$row['legal_name']})", 'gst_registration', $id, module: 'gst_registrations',
                newValues: self::auditable($row) + ['requested_status' => $status, 'principal_address' => $principal['city'] . ', ' . $principal['pin_code']]);
            $change = $this->submitChange($id, 'create', null, ['status' => $status], 'New registration', $actor, 'gst_registrations.create', $row['gstin']);
            return [$id, $change];
        });
    }

    /**
     * Save edits. Non-key fields apply at once; key-field changes become one
     * change request (applied immediately if the workflow is inactive).
     * @param array<string, mixed> $reg @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{direct: list<string>, key: list<string>, change: ?array<string, mixed>}
     */
    public function update(array $reg, array $data, array $actor, string $reason = ''): array
    {
        $this->assertWritable($reg);
        $direct = [];
        foreach (self::DIRECT_FIELDS as $f) {
            if (array_key_exists($f, $data) && self::clean($f, $data[$f]) !== self::clean($f, $reg[$f])) {
                $direct[$f] = self::clean($f, $data[$f]);
            }
        }
        $key = [];
        foreach (self::KEY_FIELDS as $f) {
            if (!array_key_exists($f, $data)) {
                continue;
            }
            $new = match ($f) {
                'gstin' => GstinValidator::normalize((string) $data[$f]),
                'pan' => strtoupper(trim((string) $data[$f])) ?: null,
                'registration_type_id' => (int) $data[$f],
                'registration_date', 'effective_from' => self::date($data[$f]),
                default => trim((string) $data[$f]),
            };
            $old = $f === 'registration_type_id' ? (int) $reg[$f] : $reg[$f];
            if ((string) $new !== (string) ($old ?? '')) {
                $key[$f] = $new;
            }
        }
        $merged = $key + $reg;
        $type = $this->type((int) $merged['registration_type_id']);
        if ($key !== []) {
            if ($this->pendingChange((int) $reg['id']) !== null) {
                throw new DomainRuleException('A change for this registration is already waiting for approval. Wait for the decision (or cancel it) before changing key details again.');
            }
            $check = $this->checkGstin((string) $merged['gstin'], $type, (string) ($merged['pan'] ?? ''), (int) $reg['id'], allowInactiveState: isset($key['gstin']) ? false : true);
            $key['pan'] = $check['pan'];
            if ((string) $key['pan'] === (string) $reg['pan']) {
                unset($key['pan']);
            }
        }
        $this->assertDates($direct + $key + $reg, $type);
        $principalChanged = $this->saveDirect($reg, $direct, $data, $actor);

        $change = null;
        if ($key !== []) {
            $old = array_intersect_key($reg, $key);
            $change = $this->submitChange((int) $reg['id'], 'update', $old, $key, $reason !== '' ? $reason : 'Key details changed', $actor, 'gst_registrations.edit', (string) $reg['gstin']);
        }
        return ['direct' => array_merge(array_keys($direct), $principalChanged ? ['principal address'] : []), 'key' => array_keys($key), 'change' => $change];
    }

    /**
     * Status change. Cancellation goes through the cancellation workflow; other
     * changes apply at once. Each needs its own permission (activate / deactivate / suspend / cancel).
     * @param array<string, mixed> $reg @param array<string, mixed> $actor
     * @return array<string, mixed>|null the change request (cancellation) or null
     */
    public function changeStatus(array $reg, string $to, ?string $reason, ?string $effectiveDate, array $actor): ?array
    {
        if (!isset(self::TRANSITIONS[$to])) {
            throw new DomainRuleException('Unknown status.');
        }
        [$action, $from] = self::TRANSITIONS[$to];
        if (!App::gate()->allows($actor, "gst_registrations.{$action}")) {
            throw new AuthorizationException("gst_registrations.{$action}");
        }
        $this->assertWritable($reg);
        if (!in_array($reg['status'], $from, true)) {
            throw new DomainRuleException('A registration that is ' . strtolower(self::STATUSES[$reg['status']]) . ' cannot be changed to ' . strtolower(self::STATUSES[$to]) . '.');
        }
        if ($reg['approval_state'] !== 'none' && $to !== 'cancelled') {
            throw new DomainRuleException('This registration has not been approved yet. It can be used once the creation request is approved.');
        }
        $reason = trim((string) $reason);
        if (in_array($to, ['suspended', 'cancelled'], true) && $reason === '') {
            throw ValidationException::withMessage('reason', 'Give a reason.');
        }
        $date = self::date($effectiveDate) ?? BusinessDate::today();
        if ($to === 'cancelled') {
            if ($date > BusinessDate::today()) {
                throw ValidationException::withMessage('effective_date', 'The cancellation date cannot be in the future.');
            }
            if ($this->pendingChange((int) $reg['id']) !== null) {
                throw new DomainRuleException('Another change for this registration is waiting for approval.');
            }
            return $this->submitChange((int) $reg['id'], 'cancel', ['status' => $reg['status'], 'cancellation_date' => $reg['cancellation_date']],
                ['status' => 'cancelled', 'cancellation_date' => $date, 'status_reason' => $reason], $reason, $actor, 'gst_registrations.cancel', (string) $reg['gstin']);
        }
        $this->applyStatus((int) $reg['id'], (string) $reg['status'], $to, $date, $reason !== '' ? $reason : null, null, $actor);
        return null;
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function archive(array $reg, string $reason, array $actor): void
    {
        if ((int) $reg['is_archived'] === 1) {
            throw new DomainRuleException('The registration is already archived.');
        }
        if (!in_array($reg['status'], ['inactive', 'cancelled'], true) && $reg['approval_state'] !== 'rejected') {
            throw new DomainRuleException('Only inactive or cancelled registrations (or rejected new registrations) can be archived. Deactivate or cancel it first.');
        }
        if ($this->pendingChange((int) $reg['id']) !== null) {
            throw new DomainRuleException('Decide or cancel the pending change first.');
        }
        if ((int) $this->db->scalar('SELECT is_default FROM gst_registration_settings WHERE gst_registration_id = ?', [$reg['id']]) === 1) {
            throw new DomainRuleException('This is the default registration for business operations. Choose another default first.');
        }
        if ((new GstLinkRegistry($this->db))->isReferenced((int) $reg['id'])) {
            throw new DomainRuleException('Other records still refer to this registration (linked accounts or transactions). It stays in the active register; it is never deleted.');
        }
        $this->db->update('gst_registrations', ['is_archived' => 1, 'archived_at' => now(), 'archived_by' => (int) $actor['id'], 'archive_reason' => trim($reason) ?: null,
            'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $reg['id']]);
        App::activity()->log('gst.registration_archived', "Archived GST registration {$reg['gstin']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            oldValues: ['is_archived' => false], newValues: ['is_archived' => true, 'reason' => trim($reason)]);
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function restore(array $reg, array $actor): void
    {
        if ((int) $reg['is_archived'] !== 1) {
            throw new DomainRuleException('The registration is not archived.');
        }
        $this->db->update('gst_registrations', ['is_archived' => 0, 'archived_at' => null, 'archived_by' => null, 'archive_reason' => null,
            'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $reg['id']]);
        App::activity()->log('gst.registration_restored', "Restored GST registration {$reg['gstin']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            oldValues: ['is_archived' => true], newValues: ['is_archived' => false]);
    }

    /** Re-submit a rejected new registration (after correcting it). @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function resubmit(array $reg, array $actor): array
    {
        if ($reg['approval_state'] !== 'rejected' || (int) $reg['is_archived'] === 1) {
            throw new DomainRuleException('Only a rejected new registration can be re-submitted.');
        }
        $last = $this->db->first("SELECT new_values FROM gst_registration_change_requests WHERE gst_registration_id = ? AND change_type = 'create' ORDER BY id DESC", [$reg['id']]);
        $status = (string) ((json_decode((string) ($last['new_values'] ?? ''), true) ?: [])['status'] ?? 'pending_verification');
        $this->db->update('gst_registrations', ['approval_state' => 'pending', 'updated_at' => now(), 'updated_by' => (int) $actor['id']], ['id' => (int) $reg['id']]);
        return $this->submitChange((int) $reg['id'], 'create', null, ['status' => $status], 'Re-submitted after correction', $actor, 'gst_registrations.create', (string) $reg['gstin']);
    }

    // ------------------------------------------------------------------ approval integration

    /**
     * Create a change request and submit it to the approval engine. An inactive
     * workflow approves at once, and the listener applies the change.
     * @param array<string, mixed>|null $old @param array<string, mixed> $new @param array<string, mixed> $actor
     * @return array<string, mixed> the change request after submission
     */
    private function submitChange(int $regId, string $type, ?array $old, array $new, string $reason, array $actor, string $permission, string $gstin): array
    {
        $now = now();
        $changeId = $this->db->insert('gst_registration_change_requests', [
            'gst_registration_id' => $regId, 'change_type' => $type, 'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE),
            'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE), 'reason' => mb_substr($reason, 0, 255), 'status' => 'pending',
            'requested_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        App::activity()->log('gst.change_requested', ucfirst($type) . " request for GST registration {$gstin}", 'gst_registration', $regId, module: 'gst_registrations',
            oldValues: $old ?? [], newValues: $new + ['change_request' => $changeId, 'type' => $type]);
        $title = match ($type) {
            'create' => "New GST registration {$gstin}",
            'cancel' => "Cancel GST registration {$gstin}",
            default => "Change key details of GST registration {$gstin}",
        };
        $request = App::approvals()->submit(self::WORKFLOWS[$type], self::SUBJECT, $changeId, 0.0, $title, $actor, $permission);
        $this->db->update('gst_registration_change_requests', ['approval_request_id' => (int) $request['id']], ['id' => $changeId]);
        if ($request['status'] === 'approved' && $this->change($changeId)['status'] === 'pending') {
            // Listener registration may not have run in this process yet.
            $this->applyDecision($request, 'approved');
        }
        return $this->change($changeId) ?? [];
    }

    /** Apply an approval decision to the change request it belongs to. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $change = $this->change((int) $request['subject_id']);
        if ($change === null || $change['status'] !== 'pending') {
            return;
        }
        $actor = ['id' => (int) ($request['requested_by'] ?? 0)];
        $reg = $this->find((int) $change['gst_registration_id']);
        if ($reg === null) {
            return;
        }
        $this->db->transaction(function () use ($change, $status, $reg, $actor): void {
            $now = now();
            if ($status !== 'approved') {
                $this->db->update('gst_registration_change_requests', ['status' => $status === 'cancelled' ? 'cancelled' : 'rejected', 'decided_at' => $now, 'updated_at' => $now], ['id' => $change['id']]);
                if ($change['change_type'] === 'create') {
                    $this->db->update('gst_registrations', ['approval_state' => 'rejected', 'updated_at' => $now], ['id' => $reg['id']]);
                }
                App::activity()->log('gst.change_' . ($status === 'cancelled' ? 'cancelled' : 'rejected'), ucfirst((string) $change['change_type']) . " request for {$reg['gstin']} was {$status}",
                    'gst_registration', (int) $reg['id'], module: 'gst_registrations', newValues: ['change_request' => (int) $change['id'], 'decision' => $status]);
                return;
            }
            $new = $change['new_values'];
            $error = null;
            if ($change['change_type'] === 'create') {
                $this->db->update('gst_registrations', ['approval_state' => 'none', 'updated_at' => $now], ['id' => $reg['id']]);
                $to = (string) ($new['status'] ?? 'pending_verification');
                if ($to !== 'pending_verification') {
                    $this->applyStatus((int) $reg['id'], 'pending_verification', $to, $reg['effective_from'] ?? BusinessDate::today(), 'Creation approved', (int) $change['id'], $actor);
                }
            } elseif ($change['change_type'] === 'update') {
                try {
                    $merged = $new + $reg;
                    $type = $this->type((int) $merged['registration_type_id']);
                    $check = $this->checkGstin((string) $merged['gstin'], $type, (string) ($merged['pan'] ?? ''), (int) $reg['id'], allowInactiveState: !isset($new['gstin']));
                    $values = array_intersect_key($new, array_flip(self::KEY_FIELDS));
                    $values['state_id'] = (int) $check['state']['id'];
                    $values['pan'] = $check['pan'];
                    $this->db->update('gst_registrations', $values + ['updated_at' => $now], ['id' => $reg['id']]);
                } catch (ValidationException | DomainRuleException $e) {
                    $error = $e->getMessage();
                }
            } elseif ($change['change_type'] === 'cancel') {
                if (!in_array($reg['status'], self::TRANSITIONS['cancelled'][1], true)) {
                    $error = 'The registration is no longer in a status that can be cancelled.';
                } else {
                    $this->db->update('gst_registrations', ['cancellation_date' => $new['cancellation_date'] ?? null, 'updated_at' => $now], ['id' => $reg['id']]);
                    $this->applyStatus((int) $reg['id'], (string) $reg['status'], 'cancelled', $new['cancellation_date'] ?? BusinessDate::today(), $new['status_reason'] ?? null, (int) $change['id'], $actor);
                    $this->db->update('gst_registration_settings', ['is_default' => 0, 'updated_at' => $now], ['gst_registration_id' => $reg['id']]);
                }
            }
            $this->db->update('gst_registration_change_requests', ['status' => $error === null ? 'approved' : 'rejected', 'decided_at' => $now, 'updated_at' => $now,
                'reason' => $error === null ? $change['reason'] : mb_substr('Approved but could not be applied: ' . $error, 0, 255)], ['id' => $change['id']]);
            App::activity()->log($error === null ? 'gst.change_applied' : 'gst.change_failed', ucfirst((string) $change['change_type']) . " request for {$reg['gstin']} " . ($error === null ? 'approved and applied' : 'could not be applied: ' . $error),
                'gst_registration', (int) $reg['id'], module: 'gst_registrations', oldValues: $change['old_values'] ?? [], newValues: $new + ['change_request' => (int) $change['id']]);
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Validate a GSTIN for a type and reject duplicates (archived registrations included).
     * @param array<string, mixed> $type
     * @return array{gstin: string, pan: ?string, state: array<string, mixed>}
     */
    public function checkGstin(string $gstin, array $type, string $pan, ?int $exceptId, bool $allowInactiveState = false): array
    {
        $r = (new GstinValidator($this->db))->validate($gstin, $type, $pan !== '' ? $pan : null, $allowInactiveState);
        if (!$r['valid']) {
            throw ValidationException::withMessage('gstin', implode(' ', $r['errors']));
        }
        $dup = $this->db->first('SELECT id, legal_name, is_archived FROM gst_registrations WHERE gstin = ?' . ($exceptId !== null ? ' AND id <> ?' : ''), $exceptId !== null ? [$r['gstin'], $exceptId] : [$r['gstin']]);
        if ($dup !== null) {
            throw ValidationException::withMessage('gstin', "GSTIN {$r['gstin']} is already registered" . ((int) $dup['is_archived'] ? ' (archived record)' : '') . '. Each GSTIN can exist only once.');
        }
        if ($pan !== '' && $type['id_basis'] !== 'pan' && !GstinValidator::isPan(strtoupper($pan))) {
            throw ValidationException::withMessage('pan', 'Enter a valid PAN (e.g. ABCPE1234F) or leave it empty.');
        }
        return ['gstin' => $r['gstin'], 'pan' => $r['pan'] ?? ($pan !== '' ? strtoupper($pan) : null), 'state' => $r['state']];
    }

    /** @return array<string, mixed> */
    public function type(int $id): array
    {
        return $this->db->first('SELECT * FROM gst_registration_types WHERE id = ?', [$id]) ?? throw ValidationException::withMessage('registration_type_id', 'Choose a registration type.');
    }

    /** @param array<string, mixed> $reg */
    public function assertWritable(array $reg): void
    {
        if ((int) $reg['is_archived'] === 1) {
            throw new DomainRuleException('This registration is archived and read-only. Restore it to make changes.');
        }
    }

    /** @param array<string, mixed> $actor */
    private function applyStatus(int $id, string $from, string $to, ?string $date, ?string $reason, ?int $changeId, array $actor): void
    {
        $values = ['status' => $to, 'status_reason' => $reason, 'updated_at' => now()];
        if ($to !== 'cancelled') {
            $values['cancellation_date'] = null;
        }
        if (($actor['id'] ?? 0) > 0) {
            $values['updated_by'] = (int) $actor['id'];
        }
        $this->db->update('gst_registrations', $values, ['id' => $id]);
        if (in_array($to, ['cancelled', 'inactive', 'suspended'], true)) {
            $this->db->update('gst_registration_settings', ['is_default' => 0, 'updated_at' => now()], ['gst_registration_id' => $id]);
        }
        $this->history($id, $from, $to, $date, $reason, $changeId, $actor);
        $gstin = (string) $this->db->scalar('SELECT gstin FROM gst_registrations WHERE id = ?', [$id]);
        App::activity()->log('gst.status_changed', "GST registration {$gstin}: " . (self::STATUSES[$from] ?? $from) . ' → ' . self::STATUSES[$to], 'gst_registration', $id, module: 'gst_registrations',
            oldValues: ['status' => $from], newValues: ['status' => $to, 'effective_date' => $date, 'reason' => $reason]);
    }

    /** @param array<string, mixed> $actor */
    private function history(int $id, ?string $from, string $to, ?string $date, ?string $reason, ?int $changeId, array $actor): void
    {
        $this->db->insert('gst_registration_status_history', ['gst_registration_id' => $id, 'from_status' => $from, 'to_status' => $to, 'effective_date' => $date,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null, 'change_request_id' => $changeId, 'changed_by' => ($actor['id'] ?? 0) > 0 ? (int) $actor['id'] : null, 'created_at' => now()]);
    }

    /**
     * @param array<string, mixed> $reg @param array<string, mixed> $direct @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return bool whether the principal address changed
     */
    private function saveDirect(array $reg, array $direct, array $data, array $actor): bool
    {
        $id = (int) $reg['id'];
        $principalChanged = false;
        $this->db->transaction(function () use ($id, $reg, $direct, $data, $actor, &$principalChanged): void {
            if ($direct !== []) {
                $this->db->update('gst_registrations', $direct + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
                App::activity()->log('gst.registration_updated', 'Updated ' . implode(', ', array_keys($direct)) . " of GST registration {$reg['gstin']}", 'gst_registration', $id,
                    module: 'gst_registrations', oldValues: self::auditable(array_intersect_key($reg, $direct)), newValues: self::auditable($direct));
            }
            if (array_key_exists('addr_line1', $data)) {
                $principal = $this->db->first('SELECT * FROM gst_registration_addresses WHERE gst_registration_id = ? AND is_principal = 1 AND status = \'active\'', [$id]);
                $new = $this->principalAddressInput($data);
                if ($principal === null) {
                    $this->db->insert('gst_registration_addresses', $new + ['gst_registration_id' => $id, 'is_principal' => 1, 'status' => 'active', 'created_by' => (int) $actor['id'],
                        'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
                    $principalChanged = true;
                } else {
                    $diff = array_filter($new, static fn ($v, string $k): bool => (string) $v !== (string) ($principal[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
                    if ($diff !== []) {
                        $this->db->update('gst_registration_addresses', $diff + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $principal['id']]);
                        $this->db->update('gst_registrations', ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
                        App::activity()->log('gst.location_updated', "Updated the principal place of business of {$reg['gstin']}", 'gst_registration', $id, module: 'gst_registrations',
                            oldValues: array_intersect_key($principal, $diff), newValues: $diff + ['address_id' => (int) $principal['id']]);
                        $principalChanged = true;
                    }
                }
            }
        });
        return $principalChanged;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function principalAddressInput(array $data): array
    {
        $state = (int) ($data['addr_state_id'] ?? 0);
        if (!$this->db->scalar('SELECT 1 FROM gst_states WHERE id = ?', [$state])) {
            throw ValidationException::withMessage('addr_state_id', 'Choose the state of the principal place of business.');
        }
        return [
            'address_line1' => trim((string) ($data['addr_line1'] ?? '')), 'address_line2' => self::clean('x', $data['addr_line2'] ?? null),
            'city' => trim((string) ($data['addr_city'] ?? '')), 'district' => self::clean('x', $data['addr_district'] ?? null), 'state_id' => $state,
            'pin_code' => trim((string) ($data['addr_pin_code'] ?? '')), 'country' => trim((string) ($data['addr_country'] ?? '')) ?: 'India',
            'contact_name' => self::clean('x', $data['addr_contact_name'] ?? null), 'contact_phone' => self::clean('x', $data['addr_contact_phone'] ?? null),
            'contact_email' => self::clean('contact_email', $data['addr_contact_email'] ?? null),
        ];
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $type */
    private function assertDates(array $data, array $type): void
    {
        $today = BusinessDate::today();
        $reg = self::date($data['registration_date'] ?? null);
        $eff = self::date($data['effective_from'] ?? null);
        if ($reg !== null && $reg > $today) {
            throw ValidationException::withMessage('registration_date', 'The registration date cannot be in the future.');
        }
        // Note: the effective date may be earlier than the registration (grant) date — liability can start before approval.
        $validity = self::date($data['validity_end_date'] ?? null);
        if ($validity !== null && !(int) $type['has_validity_period']) {
            throw ValidationException::withMessage('validity_end_date', 'A validity end date applies only to registration types with a limited period (e.g. casual or non-resident).');
        }
        if ($validity !== null && $eff !== null && $validity < $eff) {
            throw ValidationException::withMessage('validity_end_date', 'The validity end date cannot be before the effective date.');
        }
    }

    private static function date(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v !== '' && BusinessDate::isValid($v) ? $v : null;
    }

    private static function clean(string $field, mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        if ($v === '') {
            return null;
        }
        return match ($field) {
            'contact_email' => strtolower($v),
            'validity_end_date' => self::date($v),
            default => $v,
        };
    }

    /** Audit values without free-text bloat. @param array<string, mixed> $row @return array<string, mixed> */
    private static function auditable(array $row): array
    {
        unset($row['created_at'], $row['updated_at'], $row['created_by'], $row['updated_by']);
        if (isset($row['internal_notes'])) {
            $row['internal_notes'] = mb_strimwidth((string) $row['internal_notes'], 0, 120, '…');
        }
        return $row;
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private static function decodeChange(array $c): array
    {
        $c['old_values'] = $c['old_values'] !== null ? (json_decode((string) $c['old_values'], true) ?: []) : null;
        $c['new_values'] = json_decode((string) $c['new_values'], true) ?: [];
        return $c;
    }
}
