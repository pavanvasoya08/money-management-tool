<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Phase 8A: the editable list of platform categories. Creates no platforms, seller accounts,
 * credentials or transactions. Role defaults are released once (a Super Admin's later changes are kept).
 */
final class EcommerceSeeder extends Seeder
{
    /** code => [name, description] */
    public const CATEGORIES = [
        'marketplace' => ['Marketplace', 'Third-party marketplaces where many sellers list products (e.g. Amazon, Flipkart, Meesho)'],
        'online_store' => ['E-commerce store', 'Hosted store builders and online shops (e.g. Shopify, WooCommerce)'],
        'social_commerce' => ['Social commerce', 'Selling through social networks and messaging (e.g. Instagram, WhatsApp)'],
        'b2b' => ['B2B platform', 'Business-to-business portals and wholesale marketplaces'],
        'own_website' => ['Own website', 'The business\'s own web shop'],
        'offline' => ['Offline channel', 'Retail counters, exhibitions, distributors and other offline sales channels'],
        'other' => ['Other', 'Any other sales channel'],
    ];

    public function run(): void
    {
        $now = now();
        $order = 0;
        foreach (self::CATEGORIES as $code => [$name, $desc]) {
            $order += 10;
            if (!$this->db->scalar('SELECT 1 FROM ecommerce_platform_categories WHERE code = ?', [$code])) {
                $this->db->insert('ecommerce_platform_categories', ['code' => $code, 'name' => $name, 'description' => $desc, 'is_active' => 1, 'is_system' => 1,
                    'sort_order' => $order, 'created_at' => $now, 'updated_at' => $now]);
            }
        }
        $this->releaseOnce();
    }

    private function releaseOnce(): void
    {
        if ($this->db->scalar("SELECT 1 FROM system_settings WHERE setting_key = 'ecommerce_defaults_released'")) {
            return;
        }
        $now = now();
        $roles = require dirname(__DIR__, 2) . '/config/permissions.php';
        foreach (['accountant', 'manager'] as $slug) {
            $role = $this->db->scalar('SELECT id FROM roles WHERE slug = ?', [$slug]);
            if ($role === null) {
                continue;
            }
            foreach ($roles['roles'][$slug]['grants'] ?? [] as $perm) {
                if (!str_starts_with($perm, 'platforms.')) {
                    continue;
                }
                $pid = $this->db->scalar('SELECT id FROM permissions WHERE slug = ?', [$perm]);
                if ($pid !== null && !$this->db->scalar('SELECT 1 FROM role_permissions WHERE role_id = ? AND permission_id = ?', [$role, $pid])) {
                    $this->db->insert('role_permissions', ['role_id' => $role, 'permission_id' => $pid, 'data_scope' => 'all', 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        }
        $this->db->insert('system_settings', ['setting_key' => 'ecommerce_defaults_released', 'value' => '1', 'type' => 'boolean', 'group_name' => 'internal', 'created_at' => $now, 'updated_at' => $now]);
    }
}
