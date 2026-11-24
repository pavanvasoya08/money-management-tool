<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Phase 4 master data: GST states/UTs (official GST state codes), registration
 * types, partner relationship types and starter business activities.
 * Idempotent: rows are matched by code and never overwritten, so administrators'
 * edits (names, abbreviations, active flags) survive re-seeding.
 * No GST registrations, partners or transactions are created.
 */
final class GstSeeder extends Seeder
{
    /** code => [name, abbreviation, union territory, allows registration, active, notes] */
    public const STATES = [
        '01' => ['Jammu and Kashmir', 'JK', true, true, true, null],
        '02' => ['Himachal Pradesh', 'HP', false, true, true, null],
        '03' => ['Punjab', 'PB', false, true, true, null],
        '04' => ['Chandigarh', 'CH', true, true, true, null],
        '05' => ['Uttarakhand', 'UK', false, true, true, null],
        '06' => ['Haryana', 'HR', false, true, true, null],
        '07' => ['Delhi', 'DL', true, true, true, null],
        '08' => ['Rajasthan', 'RJ', false, true, true, null],
        '09' => ['Uttar Pradesh', 'UP', false, true, true, null],
        '10' => ['Bihar', 'BR', false, true, true, null],
        '11' => ['Sikkim', 'SK', false, true, true, null],
        '12' => ['Arunachal Pradesh', 'AR', false, true, true, null],
        '13' => ['Nagaland', 'NL', false, true, true, null],
        '14' => ['Manipur', 'MN', false, true, true, null],
        '15' => ['Mizoram', 'MZ', false, true, true, null],
        '16' => ['Tripura', 'TR', false, true, true, null],
        '17' => ['Meghalaya', 'ML', false, true, true, null],
        '18' => ['Assam', 'AS', false, true, true, null],
        '19' => ['West Bengal', 'WB', false, true, true, null],
        '20' => ['Jharkhand', 'JH', false, true, true, null],
        '21' => ['Odisha', 'OD', false, true, true, null],
        '22' => ['Chhattisgarh', 'CG', false, true, true, null],
        '23' => ['Madhya Pradesh', 'MP', false, true, true, null],
        '24' => ['Gujarat', 'GJ', false, true, true, null],
        '25' => ['Daman and Diu (before merger)', 'DD', true, true, false, 'Merged into code 26 from 26 Jan 2020; kept for older GSTINs'],
        '26' => ['Dadra and Nagar Haveli and Daman and Diu', 'DN', true, true, true, null],
        '27' => ['Maharashtra', 'MH', false, true, true, null],
        '28' => ['Andhra Pradesh (before division)', 'AP', false, true, false, 'Legacy code; Andhra Pradesh uses 37'],
        '29' => ['Karnataka', 'KA', false, true, true, null],
        '30' => ['Goa', 'GA', false, true, true, null],
        '31' => ['Lakshadweep', 'LD', true, true, true, null],
        '32' => ['Kerala', 'KL', false, true, true, null],
        '33' => ['Tamil Nadu', 'TN', false, true, true, null],
        '34' => ['Puducherry', 'PY', true, true, true, null],
        '35' => ['Andaman and Nicobar Islands', 'AN', true, true, true, null],
        '36' => ['Telangana', 'TS', false, true, true, null],
        '37' => ['Andhra Pradesh', 'AD', false, true, true, null],
        '38' => ['Ladakh', 'LA', true, true, true, null],
        '97' => ['Other Territory', 'OT', true, true, true, null],
        '99' => ['Centre Jurisdiction', 'CJ', false, true, true, null],
        '96' => ['Other Country (place of supply only)', 'OC', false, false, true, 'Used as a place of supply for exports; not a GSTIN state code'],
    ];

    /** code => [name, description, id basis, 14th char, has validity period] */
    public const TYPES = [
        'regular' => ['Regular', 'Normal taxpayer', 'pan', 'Z', false],
        'composition' => ['Composition', 'Composition levy taxpayer', 'pan', 'Z', false],
        'casual' => ['Casual taxable person', 'Registration for a limited period', 'pan', 'Z', true],
        'non_resident' => ['Non-resident taxable person', 'Registration for a limited period; GSTIN not PAN-based', 'other', null, true],
        'isd' => ['Input Service Distributor', 'ISD registration', 'pan', 'Z', false],
        'sez_unit' => ['SEZ unit', 'Special Economic Zone unit', 'pan', 'Z', false],
        'sez_developer' => ['SEZ developer', 'Special Economic Zone developer', 'pan', 'Z', false],
        'tds' => ['Tax deductor (TDS)', 'Registration based on TAN', 'tan', null, false],
        'tcs' => ['Tax collector / e-commerce operator (TCS)', 'E-commerce operator collecting tax at source', 'pan', null, false],
        'oidar' => ['OIDAR service provider', 'Non-resident online information and database access or retrieval services', 'other', null, false],
        'uin' => ['UN body / embassy (UIN)', 'Unique Identification Number holders', 'other', null, false],
    ];

    /** code => [name, description, counts as owner] */
    public const RELATIONSHIPS = [
        'owner' => ['Owner', 'Owns the business registered under this GSTIN', true],
        'proprietor' => ['Proprietor', 'Sole proprietor of the registered business', true],
        'partner' => ['Partner', 'Partner in the registered firm', false],
        'authorized_representative' => ['Authorized representative', 'Authorised signatory or representative', false],
        'business_associate' => ['Business associate', 'Associated with the business without ownership', false],
        'other' => ['Other', 'Any other relationship (explain in notes)', false],
    ];

    /** Starter activities only — fully editable in GST masters. */
    public const ACTIVITIES = [
        'ecommerce' => ['E-commerce business', 'Online sales through marketplaces or own website'],
        'wholesale' => ['Wholesale business', 'Sales to businesses and resellers'],
        'retail' => ['Retail business', 'Sales to end customers'],
    ];

    public function run(): void
    {
        $now = now();
        $order = 0;
        foreach (self::STATES as $code => [$name, $abbr, $ut, $allows, $active, $notes]) {
            $order += 10;
            if ($this->db->scalar('SELECT 1 FROM gst_states WHERE code = ?', [$code])) {
                continue;
            }
            $this->db->insert('gst_states', [
                'code' => (string) $code, 'name' => $name, 'abbreviation' => $abbr, 'is_union_territory' => $ut ? 1 : 0,
                'allows_registration' => $allows ? 1 : 0, 'is_active' => $active ? 1 : 0, 'notes' => $notes, 'sort_order' => $order,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $order = 0;
        foreach (self::TYPES as $code => [$name, $desc, $basis, $char, $validity]) {
            $order += 10;
            if (!$this->db->scalar('SELECT 1 FROM gst_registration_types WHERE code = ?', [$code])) {
                $this->db->insert('gst_registration_types', ['code' => $code, 'name' => $name, 'description' => $desc, 'id_basis' => $basis, 'fourteenth_char' => $char,
                    'has_validity_period' => $validity ? 1 : 0, 'is_active' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $order = 0;
        foreach (self::RELATIONSHIPS as $code => [$name, $desc, $owner]) {
            $order += 10;
            if (!$this->db->scalar('SELECT 1 FROM gst_partner_relationship_types WHERE code = ?', [$code])) {
                $this->db->insert('gst_partner_relationship_types', ['code' => $code, 'name' => $name, 'description' => $desc, 'counts_as_owner' => $owner ? 1 : 0,
                    'is_active' => 1, 'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        if (!$this->db->scalar('SELECT 1 FROM gst_business_activities LIMIT 1')) {
            $order = 0;
            foreach (self::ACTIVITIES as $code => [$name, $desc]) {
                $order += 10;
                $this->db->insert('gst_business_activities', ['code' => $code, 'name' => $name, 'description' => $desc, 'is_active' => 1, 'sort_order' => $order,
                    'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $this->releasePartnerScope();
    }

    /**
     * Phase 2 pre-granted the Partner role "view assigned GST registrations" as a
     * placeholder. Now that registrations can be mapped to partners, the Partner
     * role's default is "own" (registrations mapped to the partner's own record).
     * Applied once; if an administrator later removes it, it is not re-added.
     */
    private function releasePartnerScope(): void
    {
        if ($this->db->scalar("SELECT 1 FROM system_settings WHERE setting_key = 'gst_partner_scope_released'")) {
            return;
        }
        $now = now();
        $role = $this->db->scalar("SELECT id FROM roles WHERE slug = 'partner'");
        $own = $this->db->scalar("SELECT id FROM permissions WHERE slug = 'gst_registrations.view_own'");
        if ($role !== null && $own !== null && !$this->db->scalar('SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$role, $own])
            && $this->db->scalar("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? AND p.slug = 'gst_registrations.view'", [$role])) {
            $this->db->insert('role_permissions', ['role_id' => $role, 'permission_id' => $own, 'data_scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);
        }
        $this->db->insert('system_settings', ['setting_key' => 'gst_partner_scope_released', 'value' => '1', 'type' => 'boolean', 'group_name' => 'internal', 'created_at' => $now, 'updated_at' => $now]);
    }
}
