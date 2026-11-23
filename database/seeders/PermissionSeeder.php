<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Seeder;

/** Syncs the permission catalogue from config/permissions.php (insert missing, update labels). */
final class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach ((array) Config::get('permissions.modules', []) as $module => $definition) {
            foreach ($definition['actions'] as $action => $description) {
                $slug = "{$module}.{$action}";
                $name = $definition['label'] . ': ' . ucwords(str_replace('_', ' ', $action));
                $existing = $this->db->first('SELECT id FROM permissions WHERE slug = ?', [$slug]);
                if ($existing === null) {
                    $this->db->insert('permissions', [
                        'module' => $module, 'action' => $action, 'slug' => $slug, 'name' => $name,
                        'description' => $description, 'is_system' => 1, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    $this->db->update('permissions', ['name' => $name, 'description' => $description, 'updated_at' => $now], ['id' => $existing['id']]);
                }
            }
        }
    }
}
