<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Validator;
use App\Exceptions\ValidationException;
use Throwable;

/**
 * Typed key/value settings stored in `system_settings`. Every setting is
 * declared in definitions() with its type, default and validation rules, so
 * unknown keys can never be written.
 */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array<string, array{type: string, default: mixed, group: string, label: string, rules: string, help?: string}> */
    public static function definitions(): array
    {
        return [
            'app_name' => ['type' => 'string', 'default' => 'Business ERP', 'group' => 'general', 'label' => 'Application name', 'rules' => 'required|string|max:60'],
            'company_name' => ['type' => 'string', 'default' => '', 'group' => 'general', 'label' => 'Company / firm name', 'rules' => 'nullable|string|max:120'],
            'timezone' => ['type' => 'string', 'default' => 'Asia/Kolkata', 'group' => 'general', 'label' => 'Display timezone', 'rules' => 'required|timezone'],
            'date_format' => ['type' => 'string', 'default' => 'd M Y', 'group' => 'general', 'label' => 'Date format', 'rules' => 'required|in:d M Y,d/m/Y,d-m-Y,Y-m-d,M d Y'],
            'records_per_page' => ['type' => 'integer', 'default' => 15, 'group' => 'general', 'label' => 'Rows per page in tables', 'rules' => 'required|integer|min:5|max:100'],

            'login_max_attempts' => ['type' => 'integer', 'default' => 5, 'group' => 'security', 'label' => 'Failed logins before temporary lockout', 'rules' => 'required|integer|min:3|max:20'],
            'lockout_minutes' => ['type' => 'integer', 'default' => 15, 'group' => 'security', 'label' => 'Lockout duration (minutes)', 'rules' => 'required|integer|min:1|max:1440'],
            'password_min_length' => ['type' => 'integer', 'default' => 10, 'group' => 'security', 'label' => 'Minimum password length', 'rules' => 'required|integer|min:8|max:64'],
            'session_idle_minutes' => ['type' => 'integer', 'default' => 30, 'group' => 'security', 'label' => 'Sign out after inactivity (minutes)', 'rules' => 'required|integer|min:5|max:480'],
            'remember_me_days' => ['type' => 'integer', 'default' => 30, 'group' => 'security', 'label' => '"Remember me" duration (days)', 'rules' => 'required|integer|min:1|max:90'],
            'password_reset_expiry_minutes' => ['type' => 'integer', 'default' => 60, 'group' => 'security', 'label' => 'Password reset link validity (minutes)', 'rules' => 'required|integer|min:10|max:1440'],
            'max_concurrent_sessions' => ['type' => 'integer', 'default' => 0, 'group' => 'security', 'label' => 'Maximum simultaneous sessions per user (0 = unlimited)', 'rules' => 'required|integer|min:0|max:20'],

            'allow_user_level_grants' => ['type' => 'boolean', 'default' => true, 'group' => 'access', 'label' => 'Allow explicit per-user "Allow" grants', 'rules' => 'nullable|boolean', 'help' => 'When off, user-level Allow overrides are ignored; Deny overrides always apply.'],
            'block_self_approval' => ['type' => 'boolean', 'default' => true, 'group' => 'access', 'label' => 'Block approving your own requests', 'rules' => 'nullable|boolean', 'help' => 'Segregation of duties for approval workflows.'],
            'allow_capital_overdraw' => ['type' => 'boolean', 'default' => false, 'group' => 'finance', 'label' => 'Exception policy: allow withdrawals beyond available capital', 'rules' => 'nullable|boolean', 'help' => 'Off = a withdrawal (or debit adjustment / reversal) can never take a partner\'s posted capital below zero. On = users who also hold "Capital Withdrawals: Exceed available capital" may do so; every use is audited.'],
            'access_requests_enabled' => ['type' => 'boolean', 'default' => false, 'group' => 'access', 'label' => 'Let users request access from the "Access denied" page', 'rules' => 'nullable|boolean'],
            // Phase 4 — GST business rules (edited on GST Registrations → Masters & rules).
            'gst_single_owner_rule' => ['type' => 'boolean', 'default' => false, 'group' => 'gst', 'label' => 'Each GST registration may have only one active owner', 'rules' => 'nullable|boolean', 'help' => 'When on, a registration can have at most one active partner mapping whose relationship type counts as an owner (e.g. Owner, Proprietor).'],
            'gst_document_expiry_warning_days' => ['type' => 'integer', 'default' => 30, 'group' => 'gst', 'label' => 'Warn about expiring GST documents and registrations (days ahead)', 'rules' => 'required|integer|min:1|max:365'],
            // Phase 5 — bank rules (edited on Bank Management → Settings).
            'bank_duplicate_window_days' => ['type' => 'integer', 'default' => 3, 'group' => 'bank', 'label' => 'Possible-duplicate check window (days)', 'rules' => 'required|integer|min:0|max:30', 'help' => 'A new entry with the same account, amount and reference (or same amount and counterparty) within this many days is flagged; the user must confirm it is not a duplicate.'],
            'bank_match_tolerance_days' => ['type' => 'integer', 'default' => 3, 'group' => 'bank', 'label' => 'Reconciliation: suggest matches up to this many days apart', 'rules' => 'required|integer|min:0|max:15', 'help' => 'Suggestions need the same amount and direction; a matching reference number ranks first.'],
            'bank_reconciliation_overdue_days' => ['type' => 'integer', 'default' => 30, 'group' => 'bank', 'label' => 'Reconciliation is pending when the last completed one is older than (days)', 'rules' => 'required|integer|min:1|max:365'],
            // Phase 6 — product & supplier rules (edited on Products → Settings).
            'product_sku_auto_generate' => ['type' => 'boolean', 'default' => true, 'group' => 'products', 'label' => 'Generate SKUs automatically when none is entered', 'rules' => 'nullable|boolean'],
            'product_sku_manual_allowed' => ['type' => 'boolean', 'default' => true, 'group' => 'products', 'label' => 'Allow typing a SKU by hand', 'rules' => 'nullable|boolean', 'help' => 'Off = every SKU is generated from the pattern. Changing an existing SKU always needs the "Change SKUs" permission.'],
            'product_sku_pattern' => ['type' => 'string', 'default' => '{CAT}-{SEQ:5}', 'group' => 'products', 'label' => 'SKU pattern for products', 'rules' => 'required|string|min:6|max:60|regex:/\{SEQ(:[1-9])?\}/', 'help' => 'Tokens: {CAT} category code, {BRAND} brand code, {TYPE} product type code, {NAME} first letters of the name, {YYYY} / {YY} year, {SEQ:n} running number with n digits (required). Each filled-in prefix has its own running number.'],
            'product_variant_sku_pattern' => ['type' => 'string', 'default' => '{PARENT}-{SEQ:2}', 'group' => 'products', 'label' => 'SKU pattern for variants', 'rules' => 'required|string|min:6|max:60|regex:/\{SEQ(:[1-9])?\}/', 'help' => 'Tokens: {PARENT} the product SKU, {ATTR} the variant\'s attribute values, {SEQ:n} running number (required).'],
            'product_single_preferred_supplier' => ['type' => 'boolean', 'default' => true, 'group' => 'products', 'label' => 'Only one preferred supplier per product or variant', 'rules' => 'nullable|boolean', 'help' => 'Marking a new preferred supplier removes the mark from the previous one.'],
            'product_tax_required_for_activation' => ['type' => 'boolean', 'default' => false, 'group' => 'products', 'label' => 'Require a GST/HSN configuration before a product can be activated', 'rules' => 'nullable|boolean'],
            'supplier_gstin_unique' => ['type' => 'boolean', 'default' => true, 'group' => 'products', 'label' => 'One supplier per GSTIN', 'rules' => 'nullable|boolean', 'help' => 'Off = the same GSTIN may be saved for more than one supplier after the user confirms (e.g. separate buying relationships with one company).'],
            // Phase 7 — inventory rules (edited on Inventory → Settings).
            'inventory_valuation_method' => ['type' => 'string', 'default' => 'weighted_average', 'group' => 'inventory', 'label' => 'Default stock valuation method', 'rules' => 'required|in:fifo,weighted_average', 'help' => 'FIFO or weighted average cost. Products can choose their own method. The default cannot change while stock valued with it is on hand.'],
            'inventory_removal_method' => ['type' => 'string', 'default' => 'fefo', 'group' => 'inventory', 'label' => 'Batch picking when no batch is chosen', 'rules' => 'required|in:fefo,fifo', 'help' => 'FEFO = earliest expiry first; FIFO = oldest batch first.'],
            'inventory_expiry_warning_days' => ['type' => 'integer', 'default' => 30, 'group' => 'inventory', 'label' => 'Near-expiry warning (days)', 'rules' => 'required|integer|min:1|max:730'],
            'inventory_block_expired_dispatch' => ['type' => 'boolean', 'default' => true, 'group' => 'inventory', 'label' => 'Block expired batches from normal issues', 'rules' => 'nullable|boolean', 'help' => 'Expired stock can still be written off. Issuing it otherwise needs an expired-stock override.'],
            'inventory_transfer_in_transit' => ['type' => 'boolean', 'default' => true, 'group' => 'inventory', 'label' => 'Warehouse transfers go in transit until received', 'rules' => 'nullable|boolean', 'help' => 'Off = an approved transfer moves the stock at once. Transfers between locations of one warehouse never go in transit.'],
            'inventory_reservation_days' => ['type' => 'integer', 'default' => 7, 'group' => 'inventory', 'label' => 'Default reservation validity (days)', 'rules' => 'required|integer|min:0|max:365', 'help' => '0 = reservations do not expire unless a date is entered.'],
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();
        if (array_key_exists($key, $this->cache ?? [])) {
            return $this->cache[$key];
        }
        return self::definitions()[$key]['default'] ?? $default;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        $this->load();
        $out = [];
        foreach (self::definitions() as $key => $def) {
            $out[$key] = $this->cache[$key] ?? $def['default'];
        }
        return $out;
    }

    private function load(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        try {
            foreach ($this->db->select('SELECT setting_key, value, type FROM system_settings') as $row) {
                $this->cache[$row['setting_key']] = self::cast($row['value'], $row['type']);
            }
        } catch (Throwable) {
            // Database not migrated yet / unavailable — fall back to defaults.
        }
    }

    /**
     * Validate and persist settings. When $groups is given only those groups are
     * validated and saved (so one settings page cannot reset another's values).
     * Returns the list of keys that changed.
     *
     * @param array<string, mixed> $input
     * @param list<string>|null $groups
     * @return list<string>
     */
    public function update(array $input, int $actorId, ?array $groups = null): array
    {
        $defs = self::definitions();
        if ($groups !== null) {
            $defs = array_filter($defs, static fn (array $d): bool => in_array($d['group'], $groups, true));
        }
        $rules = array_map(static fn (array $d): string => $d['rules'], $defs);
        $labels = array_map(static fn (array $d): string => $d['label'], $defs);
        $validator = new Validator(array_intersect_key($input, $defs), $rules, $labels, $this->db);
        if ($validator->fails()) {
            throw new ValidationException($validator->errors());
        }

        $current = $this->all();
        $this->lastChange = ['old' => [], 'new' => []];
        $changed = [];
        $now = now();
        $this->db->transaction(function (Database $db) use ($validator, $defs, $current, &$changed, $now, $actorId): void {
            foreach ($validator->validated() as $key => $value) {
                $value ??= '';
                if ((string) self::serialize($value, $defs[$key]['type']) === (string) self::serialize($current[$key], $defs[$key]['type'])) {
                    continue;
                }
                $serialized = self::serialize($value, $defs[$key]['type']);
                $exists = $db->scalar('SELECT 1 FROM system_settings WHERE setting_key = ?', [$key]);
                if ($exists) {
                    $db->update('system_settings', ['value' => $serialized, 'updated_by' => $actorId, 'updated_at' => $now], ['setting_key' => $key]);
                } else {
                    $db->insert('system_settings', [
                        'setting_key' => $key, 'value' => $serialized, 'type' => $defs[$key]['type'], 'group_name' => $defs[$key]['group'],
                        'description' => $defs[$key]['label'], 'updated_by' => $actorId, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $changed[] = $key;
                $this->lastChange['old'][$key] = $current[$key];
                $this->lastChange['new'][$key] = $value;
            }
        });
        $this->cache = null;
        return $changed;
    }

    /** @var array{old: array<string, mixed>, new: array<string, mixed>} values changed by the last update() */
    public array $lastChange = ['old' => [], 'new' => []];

    public static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'integer' => (int) $value,
            'boolean' => $value === '1',
            'json' => $value === null ? null : json_decode($value, true),
            default => $value ?? '',
        };
    }

    public static function serialize(mixed $value, string $type): string
    {
        return match ($type) {
            'integer' => (string) (int) $value,
            'boolean' => $value ? '1' : '0',
            'json' => (string) json_encode($value),
            default => (string) $value,
        };
    }
}
