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
