<?php

declare(strict_types=1);

namespace App\Services\Bank;

use App\Core\App;
use App\Core\Database;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\BusinessDate;

/**
 * Many-to-many associations of bank accounts with partners, GST registrations (primary /
 * secondary) and business activities (the Phase 4 activity master). Ending a mapping keeps
 * it as history. Mappings never imply ownership, capital share or profit share.
 */
final class BankRelationService
{
    public const RELATIONSHIPS = ['account_holder' => 'Account holder', 'joint_holder' => 'Joint holder', 'authorized_operator' => 'Authorised operator', 'beneficiary' => 'Beneficiary', 'other' => 'Other'];
    public const DESIGNATIONS = ['primary' => 'Primary account', 'secondary' => 'Secondary account'];

    public function __construct(private readonly Database $db)
    {
    }

    // ---------------------------------------------------------------- partners

    /** @return list<array<string, mixed>> */
    public function partnerMappings(int $accountId): array
    {
        return $this->db->select('SELECT m.*, p.name AS partner_name, p.partner_code, u.name AS created_by_name FROM bank_account_partner_mappings m JOIN partners p ON p.id = m.partner_id
            LEFT JOIN users u ON u.id = m.created_by WHERE m.bank_account_id = ? ORDER BY m.status, m.effective_from DESC, m.id DESC', [$accountId]);
    }

    /** Accounts mapped to a partner, within a bank-account scope (alias a). @param array{0: string, 1: list<mixed>} $scope @return list<array<string, mixed>> */
    public function accountsForPartner(int $partnerId, array $scope): array
    {
        [$w, $p] = $scope;
        return $this->db->select("SELECT m.*, a.display_name, a.account_code, a.bank_name, a.account_number_last4, a.status AS account_status, a.is_central_funding
            FROM bank_account_partner_mappings m JOIN bank_accounts a ON a.id = m.bank_account_id WHERE m.partner_id = ? AND {$w} ORDER BY m.status, a.display_name", array_merge([$partnerId], $p));
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function addPartner(array $acc, array $data, array $actor): int
    {
        $this->assertOpen($acc);
        $v = $this->check($data, ['partner_id' => 'required|integer|exists:partners,id', 'relationship' => 'required|in:' . implode(',', array_keys(self::RELATIONSHIPS)),
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'notes' => 'nullable|string|max:255'], ['partner_id' => 'Partner']);
        $this->period($v);
        if ($this->db->scalar("SELECT 1 FROM bank_account_partner_mappings WHERE bank_account_id = ? AND partner_id = ? AND relationship = ? AND status = 'active'", [$acc['id'], $v['partner_id'], $v['relationship']])) {
            throw ValidationException::withMessage('partner_id', 'This partner already has an active mapping of this type on this account.');
        }
        $id = $this->db->insert('bank_account_partner_mappings', ['bank_account_id' => (int) $acc['id'], 'partner_id' => (int) $v['partner_id'], 'relationship' => $v['relationship'],
            'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'] ?: null, 'status' => $this->statusFor($v['effective_to'] ?? null), 'notes' => $v['notes'] ?: null,
            'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        $name = (string) $this->db->scalar('SELECT name FROM partners WHERE id = ?', [$v['partner_id']]);
        $this->log($acc, 'partner_mapped', "Mapped partner {$name} (" . self::RELATIONSHIPS[$v['relationship']] . ") to {$acc['account_code']}", ['entity' => 'partner_mapping', 'id' => $id] + $v, $actor);
        return $id;
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $actor */
    public function endPartner(array $acc, int $mappingId, string $date, string $reason, array $actor): void
    {
        $this->end($acc, 'bank_account_partner_mappings', $mappingId, $date, $reason, $actor, 'partner_unmapped');
    }

    // ---------------------------------------------------------------- GST registrations

    /** @return list<array<string, mixed>> */
    public function gstMappings(int $accountId): array
    {
        return $this->db->select('SELECT m.*, g.gstin, g.legal_name, g.trade_name, g.status AS registration_status, s.code AS state_code, s.name AS state_name FROM bank_account_gst_mappings m
            JOIN gst_registrations g ON g.id = m.gst_registration_id JOIN gst_states s ON s.id = g.state_id WHERE m.bank_account_id = ? ORDER BY m.status, m.designation, m.id DESC', [$accountId]);
    }

    /** Accounts mapped to a GST registration (for the GST profile). @param array{0: string, 1: list<mixed>} $scope alias a @return list<array<string, mixed>> */
    public function accountsForRegistration(int $regId, array $scope): array
    {
        [$w, $p] = $scope;
        return $this->db->select("SELECT m.*, a.display_name, a.account_code, a.bank_name, a.account_number_last4, a.status AS account_status FROM bank_account_gst_mappings m
            JOIN bank_accounts a ON a.id = m.bank_account_id WHERE m.gst_registration_id = ? AND {$w} ORDER BY m.status, m.designation, a.display_name", array_merge([$regId], $p));
    }

    /**
     * Map a GST registration. A registration has at most one active primary account: a new
     * primary demotes the previous one to secondary (recorded in history).
     * @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor
     */
    public function addGst(array $acc, array $data, array $actor): int
    {
        $this->assertOpen($acc);
        $v = $this->check($data, ['gst_registration_id' => 'required|integer|exists:gst_registrations,id', 'designation' => 'required|in:primary,secondary',
            'effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'notes' => 'nullable|string|max:255'], ['gst_registration_id' => 'GST registration']);
        $this->period($v);
        $reg = $this->db->first('SELECT id, gstin, status, is_archived FROM gst_registrations WHERE id = ?', [$v['gst_registration_id']]);
        if ((int) $reg['is_archived'] === 1 || $reg['status'] === 'cancelled') {
            throw ValidationException::withMessage('gst_registration_id', 'This GST registration is cancelled or archived.');
        }
        if ($this->db->scalar("SELECT 1 FROM bank_account_gst_mappings WHERE bank_account_id = ? AND gst_registration_id = ? AND status = 'active'", [$acc['id'], $reg['id']])) {
            throw ValidationException::withMessage('gst_registration_id', 'This registration is already mapped to this account.');
        }
        $id = $this->db->transaction(function (Database $db) use ($acc, $v, $actor, $reg): int {
            if ($v['designation'] === 'primary') {
                $this->demotePrimary((int) $reg['id'], null, $acc, $actor);
            }
            return $db->insert('bank_account_gst_mappings', ['bank_account_id' => (int) $acc['id'], 'gst_registration_id' => (int) $reg['id'], 'designation' => $v['designation'],
                'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'] ?: null, 'status' => $this->statusFor($v['effective_to'] ?? null), 'notes' => $v['notes'] ?: null,
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->log($acc, 'gst_mapped', "Mapped GST registration {$reg['gstin']} ({$v['designation']}) to {$acc['account_code']}", ['entity' => 'gst_mapping', 'id' => $id] + $v, $actor);
        return $id;
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $actor */
    public function setDesignation(array $acc, int $mappingId, string $designation, array $actor): void
    {
        $this->assertOpen($acc);
        if (!isset(self::DESIGNATIONS[$designation])) {
            throw ValidationException::withMessage('designation', 'Choose primary or secondary.');
        }
        $m = $this->db->first("SELECT * FROM bank_account_gst_mappings WHERE id = ? AND bank_account_id = ? AND status = 'active'", [$mappingId, $acc['id']]) ?? throw new DomainRuleException('Mapping not found or ended.');
        if ($m['designation'] === $designation) {
            return;
        }
        $this->db->transaction(function () use ($m, $designation, $acc, $actor, $mappingId): void {
            if ($designation === 'primary') {
                $this->demotePrimary((int) $m['gst_registration_id'], $mappingId, $acc, $actor);
            }
            $this->db->update('bank_account_gst_mappings', ['designation' => $designation, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $mappingId]);
        });
        $this->log($acc, 'gst_designation', "Changed GST mapping #{$mappingId} on {$acc['account_code']} to {$designation}", ['entity' => 'gst_mapping', 'id' => $mappingId, 'from' => $m['designation'], 'to' => $designation], $actor);
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $actor */
    public function endGst(array $acc, int $mappingId, string $date, string $reason, array $actor): void
    {
        $this->end($acc, 'bank_account_gst_mappings', $mappingId, $date, $reason, $actor, 'gst_unmapped');
    }

    // ---------------------------------------------------------------- business activities

    /** @return list<array<string, mixed>> */
    public function activityMappings(int $accountId): array
    {
        return $this->db->select('SELECT m.*, a.name AS activity_name, a.code AS activity_code FROM bank_account_business_mappings m JOIN gst_business_activities a ON a.id = m.business_activity_id
            WHERE m.bank_account_id = ? ORDER BY m.status, a.name', [$accountId]);
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function addActivity(array $acc, array $data, array $actor): int
    {
        $this->assertOpen($acc);
        $v = $this->check($data, ['business_activity_id' => 'required|integer|exists:gst_business_activities,id', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'notes' => 'nullable|string|max:255'],
            ['business_activity_id' => 'Business activity']);
        $this->period($v);
        if ($this->db->scalar("SELECT 1 FROM bank_account_business_mappings WHERE bank_account_id = ? AND business_activity_id = ? AND status = 'active'", [$acc['id'], $v['business_activity_id']])) {
            throw ValidationException::withMessage('business_activity_id', 'This activity is already mapped to this account.');
        }
        $id = $this->db->insert('bank_account_business_mappings', ['bank_account_id' => (int) $acc['id'], 'business_activity_id' => (int) $v['business_activity_id'],
            'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'] ?: null, 'status' => $this->statusFor($v['effective_to'] ?? null, 'inactive'), 'notes' => $v['notes'] ?: null,
            'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        $name = (string) $this->db->scalar('SELECT name FROM gst_business_activities WHERE id = ?', [$v['business_activity_id']]);
        $this->log($acc, 'activity_mapped', "Mapped business activity {$name} to {$acc['account_code']}", ['entity' => 'activity_mapping', 'id' => $id] + $v, $actor);
        return $id;
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function updateActivity(array $acc, int $mappingId, array $data, array $actor): void
    {
        $this->assertOpen($acc);
        $m = $this->db->first('SELECT * FROM bank_account_business_mappings WHERE id = ? AND bank_account_id = ?', [$mappingId, $acc['id']]) ?? throw new DomainRuleException('Mapping not found.');
        $v = $this->check($data, ['status' => 'required|in:active,inactive', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date', 'notes' => 'nullable|string|max:255']);
        $this->period($v);
        $new = ['status' => $v['status'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'] ?: null, 'notes' => $v['notes'] ?: null];
        $this->db->update('bank_account_business_mappings', $new + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $mappingId]);
        $this->log($acc, 'activity_updated', "Updated business-activity mapping #{$mappingId} on {$acc['account_code']}", ['entity' => 'activity_mapping', 'id' => $mappingId, 'old' => array_intersect_key($m, $new), 'new' => $new], $actor);
    }

    // ---------------------------------------------------------------- helpers

    private function demotePrimary(int $regId, ?int $exceptMapping, array $acc, array $actor): void
    {
        foreach ($this->db->select("SELECT m.id, m.bank_account_id FROM bank_account_gst_mappings m WHERE m.gst_registration_id = ? AND m.status = 'active' AND m.designation = 'primary'" . ($exceptMapping ? ' AND m.id <> ' . $exceptMapping : ''), [$regId]) as $old) {
            $this->db->update('bank_account_gst_mappings', ['designation' => 'secondary', 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $old['id']]);
            (new BankAccountService($this->db))->history((int) $old['bank_account_id'], 'mapping', null, null, ['gst_mapping' => (int) $old['id'], 'designation' => 'primary'],
                ['designation' => 'secondary'], 'Another account became the primary account for this GST registration (' . $acc['account_code'] . ')', null, $actor);
        }
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $actor */
    private function end(array $acc, string $table, int $mappingId, string $date, string $reason, array $actor, string $action): void
    {
        $this->assertOpen($acc, true);
        $m = $this->db->first('SELECT * FROM ' . $this->db->identifier($table) . " WHERE id = ? AND bank_account_id = ? AND status = 'active'", [$mappingId, $acc['id']]) ?? throw new DomainRuleException('Mapping not found or already ended.');
        if (!BusinessDate::isValid($date) || $date < $m['effective_from']) {
            throw ValidationException::withMessage('effective_to', 'The end date must be on or after the start date (' . format_date($m['effective_from']) . ').');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update($table, ['status' => 'ended', 'effective_to' => $date, 'end_reason' => mb_substr($reason, 0, 255), 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $mappingId]);
        $this->log($acc, $action, "Ended mapping #{$mappingId} on {$acc['account_code']}", ['entity' => $table, 'id' => $mappingId, 'effective_to' => $date, 'reason' => $reason], $actor);
    }

    /** @param array<string, mixed> $acc */
    private function assertOpen(array $acc, bool $allowClosed = false): void
    {
        if ((int) $acc['is_archived'] === 1) {
            throw new DomainRuleException('Archived accounts are read-only.');
        }
        if (!$allowClosed && $acc['status'] === 'closed') {
            throw new DomainRuleException('Closed accounts cannot get new mappings.');
        }
    }

    /** @param array<string, mixed> $data @param array<string, string> $rules @param array<string, string> $labels @return array<string, mixed> */
    private function check(array $data, array $rules, array $labels = []): array
    {
        $v = Validator::make($data, $rules, $labels + ['effective_from' => 'From date', 'effective_to' => 'To date']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        return $v->validated() + array_fill_keys(array_keys($rules), null);
    }

    /** @param array<string, mixed> $v */
    private function period(array $v): void
    {
        if (!empty($v['effective_to']) && $v['effective_to'] < $v['effective_from']) {
            throw ValidationException::withMessage('effective_to', 'The end date must be on or after the start date.');
        }
    }

    private function statusFor(?string $to, string $ended = 'ended'): string
    {
        return $to !== null && $to !== '' && $to < BusinessDate::today() ? $ended : 'active';
    }

    /** @param array<string, mixed> $acc @param array<string, mixed> $values @param array<string, mixed> $actor */
    private function log(array $acc, string $action, string $desc, array $values, array $actor): void
    {
        (new BankAccountService($this->db))->history((int) $acc['id'], 'mapping', null, null, null, $values, null, null, $actor);
        App::activity()->log('bank.' . $action, $desc, 'bank_account', (int) $acc['id'], module: 'bank_accounts', newValues: $values);
    }
}
