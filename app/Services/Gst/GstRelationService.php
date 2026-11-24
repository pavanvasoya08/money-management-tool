<?php

declare(strict_types=1);

namespace App\Services\Gst;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\BusinessDate;

/**
 * Everything attached to a GST registration: partner mappings, business-activity
 * mappings, business locations and the registration's settings.
 *
 * - Mappings are never deleted. "Removing" a partner ends the mapping with an
 *   end date and reason; deactivating an activity keeps the row. History is
 *   therefore the rows themselves plus the audit log.
 * - A partner mapping says nothing about ownership shares, capital or profit
 *   sharing; those live in Phase 3 and are not derived from GST mappings.
 * - The optional single-owner rule (setting gst_single_owner_rule) is the only
 *   cardinality restriction, enforced here rather than in the schema.
 */
final class GstRelationService
{
    public function __construct(private readonly Database $db)
    {
    }

    // ------------------------------------------------------------------ partners

    /** @return list<array<string, mixed>> */
    public function partnerMappings(int $regId, bool $withEnded = true): array
    {
        return $this->db->select(
            'SELECT m.*, p.partner_code, p.name AS partner_name, p.status AS partner_status, r.name AS relationship, r.counts_as_owner,
                    cu.name AS created_by_name, eu.name AS ended_by_name
             FROM gst_registration_partner_mappings m JOIN partners p ON p.id = m.partner_id JOIN gst_partner_relationship_types r ON r.id = m.relationship_type_id
             LEFT JOIN users cu ON cu.id = m.created_by LEFT JOIN users eu ON eu.id = m.ended_by
             WHERE m.gst_registration_id = ?' . ($withEnded ? '' : " AND m.status = 'active'") . " ORDER BY CASE m.status WHEN 'active' THEN 0 ELSE 1 END, m.effective_from DESC, m.id DESC",
            [$regId]
        );
    }

    /**
     * Registrations mapped to a partner, limited to what the viewer may see.
     * @param array{0: string, 1: list<mixed>} $scope GST record scope for alias g
     * @return list<array<string, mixed>>
     */
    public function registrationsForPartner(int $partnerId, array $scope, bool $withEnded = true): array
    {
        return $this->db->select(
            "SELECT m.*, g.gstin, g.legal_name, g.trade_name, g.status AS registration_status, g.is_archived, s.code AS state_code, s.name AS state_name, r.name AS relationship
             FROM gst_registration_partner_mappings m JOIN gst_registrations g ON g.id = m.gst_registration_id JOIN gst_states s ON s.id = g.state_id
             JOIN gst_partner_relationship_types r ON r.id = m.relationship_type_id
             WHERE m.partner_id = ? AND {$scope[0]}" . ($withEnded ? '' : " AND m.status = 'active'") . " ORDER BY CASE m.status WHEN 'active' THEN 0 ELSE 1 END, g.legal_name",
            array_merge([$partnerId], $scope[1])
        );
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function addPartner(array $reg, array $data, array $actor): int
    {
        $this->assertOpen($reg);
        $partner = $this->db->first('SELECT id, name, partner_code, status FROM partners WHERE id = ?', [(int) $data['partner_id']])
            ?? throw ValidationException::withMessage('partner_id', 'Choose a partner.');
        if ($partner['status'] === 'archived') {
            throw ValidationException::withMessage('partner_id', 'Archived partners cannot be mapped.');
        }
        $rel = $this->db->first('SELECT * FROM gst_partner_relationship_types WHERE id = ? AND is_active = 1', [(int) $data['relationship_type_id']])
            ?? throw ValidationException::withMessage('relationship_type_id', 'Choose a relationship type.');
        [$from, $to] = $this->period($data);
        if ($this->db->scalar(
            "SELECT 1 FROM gst_registration_partner_mappings WHERE gst_registration_id = ? AND partner_id = ? AND relationship_type_id = ? AND status = 'active'",
            [$reg['id'], $partner['id'], $rel['id']]
        )) {
            throw ValidationException::withMessage('partner_id', "{$partner['name']} is already mapped to this registration as {$rel['name']}. End that mapping first if the dates changed.");
        }
        if ((int) $rel['counts_as_owner'] === 1 && (bool) App::settings()->get('gst_single_owner_rule', false)) {
            $owner = $this->db->first(
                "SELECT p.name, r.name AS rel FROM gst_registration_partner_mappings m JOIN partners p ON p.id = m.partner_id JOIN gst_partner_relationship_types r ON r.id = m.relationship_type_id
                 WHERE m.gst_registration_id = ? AND m.status = 'active' AND r.counts_as_owner = 1",
                [$reg['id']]
            );
            if ($owner !== null) {
                throw ValidationException::withMessage('relationship_type_id', "Business rule: a GST registration may have only one owner. {$owner['name']} is already mapped as {$owner['rel']}. End that mapping first, or ask a Super Admin to change the rule.");
            }
        }
        $now = now();
        $id = $this->db->insert('gst_registration_partner_mappings', [
            'gst_registration_id' => (int) $reg['id'], 'partner_id' => (int) $partner['id'], 'relationship_type_id' => (int) $rel['id'],
            'effective_from' => $from, 'effective_to' => $to, 'status' => 'active', 'notes' => self::opt($data['notes'] ?? null),
            'created_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->touch($reg, $actor);
        App::activity()->log('gst.partner_mapped', "Mapped partner {$partner['name']} ({$partner['partner_code']}) to {$reg['gstin']} as {$rel['name']}", 'gst_registration', (int) $reg['id'],
            module: 'gst_registrations', newValues: ['entity' => 'partner_mapping', 'mapping_id' => $id, 'partner_id' => (int) $partner['id'], 'relationship' => $rel['name'], 'effective_from' => $from, 'effective_to' => $to]);
        return $id;
    }

    /** End (remove) a partner mapping; the row is kept as history. @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function endPartner(array $reg, int $mappingId, ?string $endDate, string $reason, array $actor): void
    {
        $this->assertOpen($reg, allowCancelled: true);
        $m = $this->db->first("SELECT m.*, p.name AS partner_name FROM gst_registration_partner_mappings m JOIN partners p ON p.id = m.partner_id WHERE m.id = ? AND m.gst_registration_id = ?", [$mappingId, $reg['id']])
            ?? throw new DomainRuleException('Mapping not found.');
        if ($m['status'] !== 'active') {
            throw new DomainRuleException('This mapping has already ended.');
        }
        $end = $endDate !== null && $endDate !== '' ? $endDate : BusinessDate::today();
        if (!BusinessDate::isValid($end) || $end < $m['effective_from']) {
            throw ValidationException::withMessage('effective_to', 'The end date must be a valid date on or after the start date (' . format_date((string) $m['effective_from']) . ').');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'Give a reason for removing the mapping.');
        }
        $this->db->update('gst_registration_partner_mappings', ['status' => 'ended', 'effective_to' => $end, 'end_reason' => mb_substr(trim($reason), 0, 255),
            'ended_by' => (int) $actor['id'], 'ended_at' => now(), 'updated_at' => now()], ['id' => $mappingId]);
        $this->touch($reg, $actor);
        App::activity()->log('gst.partner_unmapped', "Removed partner {$m['partner_name']} from {$reg['gstin']} (ended {$end})", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            oldValues: ['entity' => 'partner_mapping', 'mapping_id' => $mappingId, 'status' => 'active', 'effective_to' => $m['effective_to']],
            newValues: ['entity' => 'partner_mapping', 'mapping_id' => $mappingId, 'status' => 'ended', 'effective_to' => $end, 'reason' => trim($reason)]);
    }

    // ------------------------------------------------------------------ business activities

    /** @return list<array<string, mixed>> */
    public function activityMappings(int $regId): array
    {
        return $this->db->select(
            "SELECT b.*, a.name AS activity, a.code AS activity_code, a.is_active AS activity_active, cu.name AS created_by_name, uu.name AS updated_by_name
             FROM gst_registration_business_mappings b JOIN gst_business_activities a ON a.id = b.business_activity_id
             LEFT JOIN users cu ON cu.id = b.created_by LEFT JOIN users uu ON uu.id = b.updated_by
             WHERE b.gst_registration_id = ? ORDER BY CASE b.status WHEN 'active' THEN 0 ELSE 1 END, a.sort_order, b.effective_from DESC",
            [$regId]
        );
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function addActivity(array $reg, array $data, array $actor): int
    {
        $this->assertOpen($reg);
        $act = $this->db->first('SELECT * FROM gst_business_activities WHERE id = ? AND is_active = 1', [(int) $data['business_activity_id']])
            ?? throw ValidationException::withMessage('business_activity_id', 'Choose a business activity.');
        [$from, $to] = $this->period($data);
        if ($this->db->scalar("SELECT 1 FROM gst_registration_business_mappings WHERE gst_registration_id = ? AND business_activity_id = ? AND status = 'active'", [$reg['id'], $act['id']])) {
            throw ValidationException::withMessage('business_activity_id', "{$act['name']} is already mapped to this registration.");
        }
        $now = now();
        $id = $this->db->insert('gst_registration_business_mappings', ['gst_registration_id' => (int) $reg['id'], 'business_activity_id' => (int) $act['id'],
            'effective_from' => $from, 'effective_to' => $to, 'status' => 'active', 'notes' => self::opt($data['notes'] ?? null),
            'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
        $this->touch($reg, $actor);
        App::activity()->log('gst.activity_mapped', "Mapped business activity {$act['name']} to {$reg['gstin']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            newValues: ['entity' => 'business_mapping', 'mapping_id' => $id, 'activity' => $act['name'], 'effective_from' => $from, 'effective_to' => $to]);
        return $id;
    }

    /** Change dates / notes, or activate / deactivate an activity mapping. @param array<string, mixed> $reg @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function updateActivity(array $reg, int $mappingId, array $data, array $actor): void
    {
        $this->assertOpen($reg);
        $b = $this->db->first('SELECT b.*, a.name AS activity FROM gst_registration_business_mappings b JOIN gst_business_activities a ON a.id = b.business_activity_id WHERE b.id = ? AND b.gst_registration_id = ?', [$mappingId, $reg['id']])
            ?? throw new DomainRuleException('Mapping not found.');
        [$from, $to] = $this->period($data);
        $status = ($data['status'] ?? $b['status']) === 'inactive' ? 'inactive' : 'active';
        if ($status === 'inactive' && $to === null) {
            $to = BusinessDate::today() >= $from ? BusinessDate::today() : $from;
        }
        if ($status === 'active' && $b['status'] === 'inactive' && $this->db->scalar("SELECT 1 FROM gst_registration_business_mappings WHERE gst_registration_id = ? AND business_activity_id = ? AND status = 'active' AND id <> ?", [$reg['id'], $b['business_activity_id'], $mappingId])) {
            throw new DomainRuleException("{$b['activity']} already has an active mapping.");
        }
        $new = ['effective_from' => $from, 'effective_to' => $to, 'status' => $status, 'notes' => self::opt($data['notes'] ?? $b['notes'])];
        $old = array_intersect_key($b, $new);
        if ($old == $new) {
            return;
        }
        $this->db->update('gst_registration_business_mappings', $new + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $mappingId]);
        $this->touch($reg, $actor);
        App::activity()->log('gst.activity_mapping_changed', "Changed business activity {$b['activity']} on {$reg['gstin']}" . ($old['status'] !== $status ? " ({$old['status']} → {$status})" : ''),
            'gst_registration', (int) $reg['id'], module: 'gst_registrations', oldValues: $old + ['entity' => 'business_mapping', 'mapping_id' => $mappingId], newValues: $new + ['entity' => 'business_mapping', 'mapping_id' => $mappingId]);
    }

    // ------------------------------------------------------------------ locations

    /** @return list<array<string, mixed>> */
    public function locations(int $regId): array
    {
        return $this->db->select(
            "SELECT a.*, s.code AS state_code, s.name AS state_name FROM gst_registration_addresses a JOIN gst_states s ON s.id = a.state_id
             WHERE a.gst_registration_id = ? ORDER BY a.is_principal DESC, CASE a.status WHEN 'active' THEN 0 ELSE 1 END, a.id",
            [$regId]
        );
    }

    /** @return array<string, mixed>|null */
    public function principal(int $regId): ?array
    {
        return $this->db->first("SELECT a.*, s.code AS state_code, s.name AS state_name FROM gst_registration_addresses a JOIN gst_states s ON s.id = a.state_id WHERE a.gst_registration_id = ? AND a.is_principal = 1 AND a.status = 'active'", [$regId]);
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveLocation(array $reg, array $data, array $actor, ?int $locationId = null): int
    {
        $this->assertOpen($reg);
        $row = $this->locationInput($data);
        $now = now();
        if ($locationId === null) {
            $id = $this->db->insert('gst_registration_addresses', $row + ['gst_registration_id' => (int) $reg['id'], 'is_principal' => 0, 'status' => 'active',
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => $now, 'updated_at' => $now]);
            $this->touch($reg, $actor);
            App::activity()->log('gst.location_added', "Added business location {$row['city']} ({$row['pin_code']}) to {$reg['gstin']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
                newValues: ['entity' => 'location', 'address_id' => $id, 'label' => $row['label'], 'city' => $row['city'], 'pin_code' => $row['pin_code']]);
            return $id;
        }
        $loc = $this->location($reg, $locationId);
        $diff = array_filter($row, static fn ($v, string $k): bool => (string) $v !== (string) ($loc[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($diff !== []) {
            $this->db->update('gst_registration_addresses', $diff + ['updated_by' => (int) $actor['id'], 'updated_at' => $now], ['id' => $locationId]);
            $this->touch($reg, $actor);
            App::activity()->log('gst.location_updated', "Updated business location {$loc['city']} of {$reg['gstin']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
                oldValues: array_intersect_key($loc, $diff) + ['entity' => 'location', 'address_id' => $locationId], newValues: $diff + ['entity' => 'location', 'address_id' => $locationId]);
        }
        return $locationId;
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function setLocationStatus(array $reg, int $locationId, bool $active, array $actor): void
    {
        $this->assertOpen($reg);
        $loc = $this->location($reg, $locationId);
        if ((int) $loc['is_principal'] === 1 && !$active) {
            throw new DomainRuleException('The principal place of business cannot be deactivated. Mark another active location as principal first.');
        }
        $status = $active ? 'active' : 'inactive';
        if ($loc['status'] === $status) {
            return;
        }
        $this->db->update('gst_registration_addresses', ['status' => $status, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $locationId]);
        $this->touch($reg, $actor);
        App::activity()->log('gst.location_' . ($active ? 'activated' : 'deactivated'), ($active ? 'Activated' : 'Deactivated') . " business location {$loc['city']} of {$reg['gstin']}", 'gst_registration', (int) $reg['id'],
            module: 'gst_registrations', oldValues: ['entity' => 'location', 'address_id' => $locationId, 'status' => $loc['status']], newValues: ['entity' => 'location', 'address_id' => $locationId, 'status' => $status]);
    }

    /** Make an active location the principal place of business (exactly one principal). @param array<string, mixed> $reg @param array<string, mixed> $actor */
    public function makePrincipal(array $reg, int $locationId, array $actor): void
    {
        $this->assertOpen($reg);
        $loc = $this->location($reg, $locationId);
        if ($loc['status'] !== 'active') {
            throw new DomainRuleException('Activate the location before making it the principal place of business.');
        }
        if ((int) $loc['is_principal'] === 1) {
            return;
        }
        $old = $this->principal((int) $reg['id']);
        $this->db->transaction(function () use ($reg, $locationId, $actor): void {
            $this->db->update('gst_registration_addresses', ['is_principal' => 0, 'updated_at' => now(), 'updated_by' => (int) $actor['id']], ['gst_registration_id' => (int) $reg['id'], 'is_principal' => 1]);
            $this->db->update('gst_registration_addresses', ['is_principal' => 1, 'updated_at' => now(), 'updated_by' => (int) $actor['id']], ['id' => $locationId]);
        });
        $this->touch($reg, $actor);
        App::activity()->log('gst.principal_changed', "Principal place of business of {$reg['gstin']} changed to {$loc['city']}", 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            oldValues: ['entity' => 'location', 'principal_address_id' => $old['id'] ?? null], newValues: ['entity' => 'location', 'principal_address_id' => $locationId]);
    }

    /** @param array<string, mixed> $reg @return array<string, mixed> */
    public function location(array $reg, int $locationId): array
    {
        return $this->db->first('SELECT * FROM gst_registration_addresses WHERE id = ? AND gst_registration_id = ?', [$locationId, $reg['id']])
            ?? throw new DomainRuleException('Location not found.');
    }

    // ------------------------------------------------------------------ settings

    /** @return array<string, mixed> */
    public function settings(int $regId): array
    {
        return $this->db->first(
            'SELECT st.*, ps.name AS place_of_supply_name, ps.code AS place_of_supply_code, ds.name AS default_state_name, ds.code AS default_state_code,
                    t.name AS applicable_type_name, u.name AS updated_by_name
             FROM gst_registration_settings st LEFT JOIN gst_states ps ON ps.id = st.default_place_of_supply_state_id LEFT JOIN gst_states ds ON ds.id = st.default_state_id
             LEFT JOIN gst_registration_types t ON t.id = st.applicable_registration_type_id LEFT JOIN users u ON u.id = st.updated_by
             WHERE st.gst_registration_id = ?',
            [$regId]
        ) ?? ['gst_registration_id' => $regId, 'is_default' => 0, 'default_place_of_supply_state_id' => null, 'default_state_id' => null, 'applicable_registration_type_id' => null,
            'prices_include_tax' => 0, 'e_invoicing_applicable' => 0, 'e_way_bill_applicable' => 0, 'effective_from' => null, 'effective_to' => null, 'notes' => null];
    }

    /** The registration marked as the default for business operations, if any. @return array<string, mixed>|null */
    public function defaultRegistration(): ?array
    {
        return $this->db->first('SELECT g.id, g.gstin, g.legal_name FROM gst_registration_settings st JOIN gst_registrations g ON g.id = st.gst_registration_id WHERE st.is_default = 1');
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveSettings(array $reg, array $data, array $actor): void
    {
        $this->assertOpen($reg);
        $current = $this->settings((int) $reg['id']);
        foreach (['default_place_of_supply_state_id', 'default_state_id'] as $f) {
            if ((int) ($data[$f] ?? 0) > 0 && !$this->db->scalar('SELECT 1 FROM gst_states WHERE id = ?', [(int) $data[$f]])) {
                throw ValidationException::withMessage($f, 'Choose a state from the list.');
            }
        }
        if ((int) ($data['applicable_registration_type_id'] ?? 0) > 0 && !$this->db->scalar('SELECT 1 FROM gst_registration_types WHERE id = ?', [(int) $data['applicable_registration_type_id']])) {
            throw ValidationException::withMessage('applicable_registration_type_id', 'Choose a registration type.');
        }
        $from = self::opt($data['effective_from'] ?? null);
        $to = self::opt($data['effective_to'] ?? null);
        foreach (['effective_from' => $from, 'effective_to' => $to] as $f => $v) {
            if ($v !== null && !BusinessDate::isValid($v)) {
                throw ValidationException::withMessage($f, 'Enter a valid date.');
            }
        }
        if ($from !== null && $to !== null && $to < $from) {
            throw ValidationException::withMessage('effective_to', 'The end date cannot be before the start date.');
        }
        $isDefault = !empty($data['is_default']) ? 1 : 0;
        if ($isDefault === 1 && ($reg['status'] !== 'active' || $reg['approval_state'] !== 'none')) {
            throw ValidationException::withMessage('is_default', 'Only an active, approved registration can be the default for business operations.');
        }
        $new = [
            'is_default' => $isDefault,
            'default_place_of_supply_state_id' => (int) ($data['default_place_of_supply_state_id'] ?? 0) ?: null,
            'default_state_id' => (int) ($data['default_state_id'] ?? 0) ?: null,
            'applicable_registration_type_id' => (int) ($data['applicable_registration_type_id'] ?? 0) ?: null,
            'prices_include_tax' => !empty($data['prices_include_tax']) ? 1 : 0,
            'e_invoicing_applicable' => !empty($data['e_invoicing_applicable']) ? 1 : 0,
            'e_way_bill_applicable' => !empty($data['e_way_bill_applicable']) ? 1 : 0,
            'effective_from' => $from, 'effective_to' => $to, 'notes' => self::opt($data['notes'] ?? null),
        ];
        $old = array_intersect_key($current, $new);
        $changed = array_filter($new, static fn ($v, string $k): bool => (string) $v !== (string) ($old[$k] ?? ''), ARRAY_FILTER_USE_BOTH);
        if ($changed === []) {
            return;
        }
        $this->db->transaction(function () use ($reg, $new, $actor, $isDefault): void {
            if ($isDefault === 1) {
                $this->db->execute('UPDATE gst_registration_settings SET is_default = 0, updated_at = ? WHERE is_default = 1 AND gst_registration_id <> ?', [now(), (int) $reg['id']]);
            }
            if ($this->db->scalar('SELECT 1 FROM gst_registration_settings WHERE gst_registration_id = ?', [$reg['id']])) {
                $this->db->update('gst_registration_settings', $new + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['gst_registration_id' => (int) $reg['id']]);
            } else {
                $this->db->insert('gst_registration_settings', $new + ['gst_registration_id' => (int) $reg['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->touch($reg, $actor);
        App::activity()->log('gst.settings_changed', "Changed settings of GST registration {$reg['gstin']}: " . implode(', ', array_keys($changed)), 'gst_registration', (int) $reg['id'], module: 'gst_registrations',
            oldValues: array_intersect_key($old, $changed) + ['entity' => 'settings'], newValues: $changed + ['entity' => 'settings']);
    }

    // ------------------------------------------------------------------ helpers

    /** @param array<string, mixed> $reg */
    private function assertOpen(array $reg, bool $allowCancelled = false): void
    {
        if ((int) $reg['is_archived'] === 1) {
            throw new DomainRuleException('This registration is archived and read-only. Restore it to make changes.');
        }
        if (!$allowCancelled && $reg['status'] === 'cancelled') {
            throw new DomainRuleException('This registration is cancelled. Its mappings and locations are kept as history and cannot be changed.');
        }
    }

    /** @param array<string, mixed> $data @return array{0: string, 1: ?string} */
    private function period(array $data): array
    {
        $from = trim((string) ($data['effective_from'] ?? ''));
        $to = trim((string) ($data['effective_to'] ?? ''));
        if (!BusinessDate::isValid($from)) {
            throw ValidationException::withMessage('effective_from', 'Enter the date from which this applies.');
        }
        if ($to !== '' && (!BusinessDate::isValid($to) || $to < $from)) {
            throw ValidationException::withMessage('effective_to', 'The end date must be on or after the start date.');
        }
        return [$from, $to !== '' ? $to : null];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function locationInput(array $data): array
    {
        $state = (int) ($data['state_id'] ?? 0);
        if (!$this->db->scalar('SELECT 1 FROM gst_states WHERE id = ?', [$state])) {
            throw ValidationException::withMessage('state_id', 'Choose a state.');
        }
        foreach (['address_line1' => 'Address line 1', 'city' => 'City', 'pin_code' => 'PIN code'] as $f => $label) {
            if (trim((string) ($data[$f] ?? '')) === '') {
                throw ValidationException::withMessage($f, "{$label} is required.");
            }
        }
        $country = trim((string) ($data['country'] ?? '')) ?: 'India';
        $pin = trim((string) $data['pin_code']);
        if (strcasecmp($country, 'India') === 0 && preg_match('/^[1-9][0-9]{5}$/', $pin) !== 1) {
            throw ValidationException::withMessage('pin_code', 'Enter a 6-digit Indian PIN code.');
        }
        $email = strtolower(trim((string) ($data['contact_email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::withMessage('contact_email', 'Enter a valid email address.');
        }
        return [
            'label' => self::opt($data['label'] ?? null), 'address_line1' => mb_substr(trim((string) $data['address_line1']), 0, 200),
            'address_line2' => self::opt($data['address_line2'] ?? null), 'city' => mb_substr(trim((string) $data['city']), 0, 100),
            'district' => self::opt($data['district'] ?? null), 'state_id' => $state, 'pin_code' => $pin, 'country' => mb_substr($country, 0, 80),
            'contact_name' => self::opt($data['contact_name'] ?? null), 'contact_phone' => self::opt($data['contact_phone'] ?? null), 'contact_email' => $email !== '' ? $email : null,
        ];
    }

    /** @param array<string, mixed> $reg @param array<string, mixed> $actor */
    private function touch(array $reg, array $actor): void
    {
        $this->db->update('gst_registrations', ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => (int) $reg['id']]);
    }

    private static function opt(mixed $v): ?string
    {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, 500);
    }
}
