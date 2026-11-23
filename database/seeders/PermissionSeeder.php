<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Config;
use App\Core\Seeder;

/**
 * Syncs config/modules.php into `modules`, `permissions` and `module_fields`.
 * Inserts what is missing and refreshes labels/order. It never deletes rows
 * and never overwrites administrator choices (module enabled flag, field defaults).
 */
final class PermissionSeeder extends Seeder
{
    /** @var list<string> permission slugs inserted during this run (used by RoleSeeder) */
    public static array $created = [];

    public function run(): void
    {
        self::$created = [];
        $now = now();
        $moduleOrder = 0;
        foreach ((array) Config::get('modules', []) as $key => $def) {
            $moduleOrder += 10;
            $module = $this->db->first('SELECT id FROM modules WHERE module_key = ?', [$key]);
            $moduleData = [
                'name' => $def['name'], 'icon' => $def['icon'] ?? null, 'phase' => (int) ($def['phase'] ?? 1),
                'sort_order' => $moduleOrder, 'super_admin_only' => !empty($def['super_admin_only']) ? 1 : 0, 'updated_at' => $now,
            ];
            if ($module === null) {
                $moduleId = $this->db->insert('modules', ['module_key' => $key, 'is_active' => 1, 'created_at' => $now] + $moduleData);
            } else {
                $moduleId = (int) $module['id'];
                $this->db->update('modules', $moduleData, ['id' => $moduleId]);
            }

            $order = 0;
            foreach ($def['actions'] as $action => $label) {
                $order++;
                $slug = "{$key}.{$action}";
                $name = $def['name'] . ': ' . $label;
                $existing = $this->db->first('SELECT id FROM permissions WHERE slug = ?', [$slug]);
                if ($existing === null) {
                    $this->db->insert('permissions', [
                        'module' => $key, 'action' => $action, 'slug' => $slug, 'name' => $name, 'description' => $label,
                        'module_id' => $moduleId, 'sort_order' => $order, 'is_system' => 1, 'is_active' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    self::$created[] = $slug;
                } else {
                    $this->db->update('permissions', ['name' => $name, 'description' => $label, 'module_id' => $moduleId, 'sort_order' => $order, 'updated_at' => $now], ['id' => $existing['id']]);
                }
            }

            $fieldOrder = 0;
            foreach ($def['fields'] ?? [] as $field => [$label, $sensitive, $mask, $default]) {
                $fieldOrder++;
                $existing = $this->db->first('SELECT id FROM module_fields WHERE module_id = ? AND field_key = ?', [$moduleId, $field]);
                if ($existing === null) {
                    $this->db->insert('module_fields', [
                        'module_id' => $moduleId, 'field_key' => $field, 'label' => $label, 'is_sensitive' => $sensitive ? 1 : 0,
                        'mask_type' => $mask, 'default_access' => $default, 'sort_order' => $fieldOrder, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                } else {
                    $this->db->update('module_fields', ['label' => $label, 'is_sensitive' => $sensitive ? 1 : 0, 'mask_type' => $mask, 'sort_order' => $fieldOrder, 'updated_at' => $now], ['id' => $existing['id']]);
                }
            }
        }
    }
}
