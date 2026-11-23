<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Core\Database;
use App\Exceptions\AuthorizationException;

/**
 * Server-side field-level security. Every page, export and API response must
 * pass records through present(); every write must pass through assertWritable().
 *
 * Resolution for a user and field (deterministic):
 *   1. Super Admin                → editable
 *   2. User field override        → that value
 *   3. Rules of the user's active roles → the MOST permissive rule
 *   4. No rule                    → the field's default access
 */
final class FieldPolicy
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $fields = [];
    /** @var array<string, array<string, FieldAccess>> */
    private array $cache = [];

    public function __construct(private readonly Database $db, private readonly Gate $gate)
    {
    }

    /** @return list<array<string, mixed>> */
    public function fields(string $module): array
    {
        return $this->fields[$module] ??= $this->db->select(
            'SELECT f.* FROM module_fields f JOIN modules m ON m.id = f.module_id WHERE m.module_key = ? ORDER BY f.sort_order, f.id',
            [$module]
        );
    }

    /** @param array<string, mixed> $user @return array<string, FieldAccess> field key => access */
    public function accessMap(array $user, string $module): array
    {
        $key = $user['id'] . '|' . $module;
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $fields = $this->fields($module);
        $map = [];
        if ($this->gate->isSuperAdmin($user)) {
            foreach ($fields as $f) {
                $map[$f['field_key']] = FieldAccess::Editable;
            }
            return $this->cache[$key] = $map;
        }
        $ids = array_column($fields, 'id');
        $roleRules = [];
        $userRules = [];
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->select(
                "SELECT rfp.field_id, rfp.access FROM role_field_permissions rfp
                 JOIN user_roles ur ON ur.role_id = rfp.role_id JOIN roles r ON r.id = ur.role_id AND r.is_active = 1
                 WHERE ur.user_id = ? AND rfp.field_id IN ({$in})",
                array_merge([(int) $user['id']], $ids)
            ) as $row) {
                $access = FieldAccess::from($row['access']);
                $roleRules[$row['field_id']] = isset($roleRules[$row['field_id']]) ? FieldAccess::widest($roleRules[$row['field_id']], $access) : $access;
            }
            foreach ($this->db->select("SELECT field_id, access FROM user_field_overrides WHERE user_id = ? AND field_id IN ({$in})", array_merge([(int) $user['id']], $ids)) as $row) {
                $userRules[$row['field_id']] = FieldAccess::from($row['access']);
            }
        }
        foreach ($fields as $f) {
            $map[$f['field_key']] = $userRules[$f['id']] ?? $roleRules[$f['id']] ?? FieldAccess::from($f['default_access']);
        }
        return $this->cache[$key] = $map;
    }

    /** Explain where a field's access comes from (for the UI and preview). @param array<string, mixed> $user */
    public function explain(array $user, string $module, string $field): string
    {
        if ($this->gate->isSuperAdmin($user)) {
            return 'Super Admin';
        }
        $row = $this->db->first(
            'SELECT o.access FROM user_field_overrides o JOIN module_fields f ON f.id = o.field_id JOIN modules m ON m.id = f.module_id
             WHERE o.user_id = ? AND m.module_key = ? AND f.field_key = ?',
            [(int) $user['id'], $module, $field]
        );
        if ($row !== null) {
            return 'User override';
        }
        $roles = $this->db->select(
            'SELECT r.name, rfp.access FROM role_field_permissions rfp JOIN user_roles ur ON ur.role_id = rfp.role_id
             JOIN roles r ON r.id = ur.role_id AND r.is_active = 1 JOIN module_fields f ON f.id = rfp.field_id JOIN modules m ON m.id = f.module_id
             WHERE ur.user_id = ? AND m.module_key = ? AND f.field_key = ?',
            [(int) $user['id'], $module, $field]
        );
        return $roles === [] ? 'Default' : 'Role: ' . implode(', ', array_column($roles, 'name'));
    }

    /** @param array<string, mixed> $user */
    public function access(array $user, string $module, string $field): FieldAccess
    {
        return $this->accessMap($user, $module)[$field] ?? FieldAccess::Editable;
    }

    /**
     * Remove hidden fields and mask masked ones. Unregistered keys pass through unchanged.
     * @param array<string, mixed> $user @param array<string, mixed> $record @return array<string, mixed>
     */
    public function present(array $user, string $module, array $record): array
    {
        foreach ($this->accessMap($user, $module) as $field => $access) {
            if (!array_key_exists($field, $record)) {
                continue;
            }
            if ($access === FieldAccess::Hidden) {
                unset($record[$field]);
            } elseif ($access === FieldAccess::Masked) {
                $record[$field] = self::mask($record[$field], $this->maskType($module, $field));
            }
        }
        return $record;
    }

    /** @param array<string, mixed> $user @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public function presentMany(array $user, string $module, array $rows): array
    {
        return array_map(fn (array $r): array => $this->present($user, $module, $r), $rows);
    }

    /**
     * Reject writes to fields the user may not edit — including crafted requests
     * that submit hidden, masked, visible-only or read-only fields.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $input   submitted values
     * @param array<string, mixed>|null $current existing record (null when creating)
     */
    public function assertWritable(array $user, string $module, array $input, ?array $current = null): void
    {
        foreach ($this->accessMap($user, $module) as $field => $access) {
            if (!array_key_exists($field, $input) || $access->canEdit()) {
                continue;
            }
            $unchanged = $current !== null && (string) ($current[$field] ?? '') === (string) ($input[$field] ?? '');
            if (!$unchanged) {
                throw new AuthorizationException("{$module}.field.{$field}", "You are not allowed to change the field \"{$field}\".");
            }
        }
    }

    public function maskType(string $module, string $field): string
    {
        foreach ($this->fields($module) as $f) {
            if ($f['field_key'] === $field) {
                return (string) $f['mask_type'];
            }
        }
        return 'full';
    }

    public static function mask(mixed $value, string $type): string
    {
        $value = (string) ($value ?? '');
        if ($value === '') {
            return '';
        }
        $len = mb_strlen($value);
        return match ($type) {
            'last4' => str_repeat('X', max(2, $len - 4)) . mb_substr($value, -4),
            'email' => (static function (string $v): string {
                [$local, $domain] = array_pad(explode('@', $v, 2), 2, '');
                return mb_substr($local, 0, 1) . str_repeat('*', max(3, mb_strlen($local) - 1)) . ($domain !== '' ? '@' . $domain : '');
            })($value),
            'phone' => str_repeat('*', max(2, $len - 4)) . mb_substr($value, -4),
            'amount' => '₹ ••••••',
            default => '••••••••',
        };
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
