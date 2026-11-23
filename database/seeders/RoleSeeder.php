<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Seeder;

/**
 * Creates the five initial system roles and their starting grants.
 * Existing grants are never removed, so changes made by administrators survive re-seeding.
 */
final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach ((array) Config::get('permissions.roles', []) as $slug => $role) {
            $row = $this->db->first('SELECT id FROM roles WHERE slug = ?', [$slug]);
            $roleId = $row === null
                ? $this->db->insert('roles', [
                    'name' => $role['name'], 'slug' => $slug, 'description' => $role['description'],
                    'is_system' => 1, 'is_super_admin' => $role['is_super_admin'] ? 1 : 0, 'is_active' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ])
                : (int) $row['id'];

            foreach ($role['grants'] as $permissionSlug) {
                $permission = $this->db->first('SELECT id FROM permissions WHERE slug = ?', [$permissionSlug]);
                if ($permission === null) {
                    continue;
                }
                $exists = $this->db->scalar('SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$roleId, $permission['id']]);
                if (!$exists) {
                    $this->db->insert('role_permissions', [
                        'role_id' => $roleId, 'permission_id' => $permission['id'], 'data_scope' => 'all',
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        }
    }
}
