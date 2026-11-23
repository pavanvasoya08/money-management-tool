<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Config;
use App\Core\Database;
use App\Core\Schema\Schema;
use App\Exceptions\DomainRuleException;

/**
 * Assign business resources (partners, GST registrations, bank accounts,
 * platforms, warehouses, …) to users. Resource types come from
 * config/resources.php and become available as soon as their table exists.
 */
final class ResourceAssignmentService extends AdminService
{
    /** @return array<string, array{label: string, table: string, label_column: string, phase: int, available: bool}> */
    public function types(): array
    {
        $schema = new Schema($this->db);
        $out = [];
        foreach ((array) Config::get('resources', []) as $key => $def) {
            $out[$key] = $def + ['available' => $schema->hasTable($def['table'])];
        }
        return $out;
    }

    /** @return list<array{id: int, label: string}> */
    public function options(string $type, string $search = '', int $limit = 200): array
    {
        $def = $this->type($type);
        $table = $this->db->identifier($def['table']);
        $label = $this->db->identifier($def['label_column']);
        $sql = "SELECT id, {$label} AS label FROM {$table}";
        $params = [];
        if ($type === 'user') {
            $sql .= ' WHERE deleted_at IS NULL';
        }
        if ($search !== '') {
            $sql .= ($type === 'user' ? ' AND ' : ' WHERE ') . "{$label} LIKE ? ESCAPE '!'";
            $params[] = Database::likeContains($search);
        }
        $limit = max(1, min(500, $limit));
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'label' => (string) $r['label']], $this->db->select($sql . " ORDER BY {$label} LIMIT {$limit}", $params));
    }

    /** @return list<int> */
    public function assigned(int $userId, string $type): array
    {
        return array_map('intval', array_column($this->db->select('SELECT resource_id FROM user_resource_assignments WHERE user_id = ? AND resource_type = ? ORDER BY resource_id', [$userId, $type]), 'resource_id'));
    }

    /** @return array<string, int> resource type => count */
    public function counts(int $userId): array
    {
        $out = [];
        foreach ($this->db->select('SELECT resource_type, COUNT(*) AS n FROM user_resource_assignments WHERE user_id = ? GROUP BY resource_type', [$userId]) as $r) {
            $out[$r['resource_type']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Replace a user's assignments of one resource type.
     * @param list<int|string> $ids @param array<string, mixed> $actor
     */
    public function sync(int $userId, string $type, array $ids, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $this->forbidSelf($actor, $userId);
        $def = $this->type($type);
        $ids = self::intIds($ids);
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $found = (int) $this->db->scalar('SELECT COUNT(*) FROM ' . $this->db->identifier($def['table']) . " WHERE id IN ({$in})", $ids);
            if ($found !== count($ids)) {
                throw new DomainRuleException('One or more selected records no longer exist.');
            }
        }
        $before = $this->assigned($userId, $type);
        if ($before === $ids || (array_diff($before, $ids) === [] && array_diff($ids, $before) === [])) {
            return;
        }
        $this->db->transaction(function (Database $db) use ($userId, $type, $ids, $before, $actor): void {
            foreach (array_diff($before, $ids) as $rid) {
                $db->delete('user_resource_assignments', ['user_id' => $userId, 'resource_type' => $type, 'resource_id' => $rid]);
            }
            foreach (array_diff($ids, $before) as $rid) {
                $db->insert('user_resource_assignments', ['user_id' => $userId, 'resource_type' => $type, 'resource_id' => $rid, 'assigned_by' => (int) $actor['id'], 'created_at' => now()]);
            }
        });
        $this->audit('resource.assignment_changed', "Changed {$def['label']} assignments", 'user', $userId, [$type => $before], [$type => $ids], affectedUser: $userId);
        $this->flush();
    }

    /** @return array{label: string, table: string, label_column: string, phase: int, available: bool} */
    private function type(string $type): array
    {
        $def = $this->types()[$type] ?? throw new DomainRuleException('Unknown resource type.');
        if (!$def['available']) {
            throw new DomainRuleException("{$def['label']} become available when their module is released (Phase {$def['phase']}).");
        }
        return $def;
    }
}
