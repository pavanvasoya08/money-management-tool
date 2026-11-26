<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;

/** Platform categories (marketplace, online store, social commerce, B2B, own website, offline …) — an editable list. */
final class PlatformCategoryService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT c.*, (SELECT COUNT(*) FROM ecommerce_platforms p WHERE p.category_id = c.id) AS platform_count,
                (SELECT COUNT(*) FROM ecommerce_platforms p WHERE p.category_id = c.id AND p.status <> \'archived\') AS open_count
            FROM ecommerce_platform_categories c' . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name');
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function save(array $data, ?int $id, array $actor): int
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) ($data['name'] ?? '')));
        $code = strtolower(trim((string) ($data['code'] ?? '')));
        $desc = trim((string) ($data['description'] ?? ''));
        $sort = (string) ($data['sort_order'] ?? '0');
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw ValidationException::withMessage('name', 'Enter a name of 2–100 characters.');
        }
        if ($code === '') {
            $code = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($name)), '_');
        }
        if (preg_match('/^[a-z][a-z0-9_]{1,39}$/', $code) !== 1) {
            throw ValidationException::withMessage('code', 'Use 2–40 lowercase letters, digits or underscores.');
        }
        if (!ctype_digit($sort) || (int) $sort > 9999) {
            throw ValidationException::withMessage('sort_order', 'Enter a whole number.');
        }
        if ($this->db->scalar('SELECT 1 FROM ecommerce_platform_categories WHERE (code = ? OR LOWER(name) = LOWER(?)) AND id <> ?', [$code, $name, (int) $id])) {
            throw ValidationException::withMessage('name', 'A category with this name or code already exists.');
        }
        $row = ['name' => $name, 'description' => $desc !== '' ? mb_substr($desc, 0, 255) : null, 'sort_order' => (int) $sort, 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($id === null) {
            $id = $this->db->insert('ecommerce_platform_categories', $row + ['code' => $code, 'is_active' => 1, 'is_system' => 0, 'created_by' => (int) $actor['id'], 'created_at' => now()]);
            $old = null;
        } else {
            $c = $this->db->first('SELECT * FROM ecommerce_platform_categories WHERE id = ?', [$id]) ?? throw new DomainRuleException('Category not found.');
            $active = !empty($data['is_active']) ? 1 : 0;
            if (!$active && (int) $c['is_active'] && $this->db->scalar("SELECT 1 FROM ecommerce_platforms WHERE category_id = ? AND status <> 'archived'", [$id])) {
                throw ValidationException::withMessage('is_active', 'Platforms use this category. Move them to another category before deactivating it.');
            }
            $row['is_active'] = $active;
            if (!(int) $c['is_system']) {
                $row['code'] = $code;
            }
            $this->db->update('ecommerce_platform_categories', $row, ['id' => $id]);
            $old = ['code' => $c['code'], 'name' => $c['name'], 'active' => (int) $c['is_active']];
        }
        App::activity()->log('platforms.category_saved', "Saved platform category {$name}", 'platform_category', $id, module: 'platforms',
            oldValues: $old, newValues: ['code' => $row['code'] ?? $code, 'name' => $name, 'active' => $row['is_active'] ?? 1]);
        return $id;
    }
}
