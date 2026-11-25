<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Files\SecureFileStore;

/**
 * Catalogue masters: nested categories (unlimited depth, no circular parents), brands,
 * variant attributes with their allowed values, and product types. Masters referenced by
 * products are archived or deactivated, never deleted. Every change is audited.
 */
final class CatalogService
{
    public const MAX_DEPTH = 10;

    public function __construct(private readonly Database $db)
    {
    }

    public static function media(): SecureFileStore
    {
        return new SecureFileStore(App::basePath('storage/uploads/product-media'));
    }

    // ---------------------------------------------------------------- categories

    /**
     * Categories as a depth-first ordered tree (each row has depth and path).
     * @return list<array<string, mixed>>
     */
    public function categoryTree(bool $withArchived = false, bool $activeOnly = false): array
    {
        $rows = $this->db->select('SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id OR p.subcategory_id = c.id) AS product_count
            FROM product_categories c' . ($withArchived ? '' : ' WHERE c.is_archived = 0') . ($activeOnly ? ($withArchived ? ' WHERE' : ' AND') . " c.status = 'active'" : '') . ' ORDER BY c.display_order, c.name');
        $byParent = [];
        $ids = array_flip(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as $r) {
            $parent = $r['parent_id'] !== null && isset($ids[(int) $r['parent_id']]) ? (int) $r['parent_id'] : 0;
            $byParent[$parent][] = $r;
        }
        $out = [];
        $walk = static function (int $parent, int $depth, string $path) use (&$walk, &$out, $byParent): void {
            foreach ($byParent[$parent] ?? [] as $r) {
                $r['depth'] = $depth;
                $r['path'] = $path === '' ? $r['name'] : $path . ' › ' . $r['name'];
                $r['children'] = count($byParent[(int) $r['id']] ?? []);
                $out[] = $r;
                $walk((int) $r['id'], $depth + 1, $r['path']);
            }
        };
        $walk(0, 0, '');
        return $out;
    }

    /** @return array<string, mixed>|null */
    public function category(int $id): ?array
    {
        return $this->db->first('SELECT * FROM product_categories WHERE id = ?', [$id]);
    }

    /** All descendants of a category (not including itself). @return list<int> */
    public function descendantIds(int $id): array
    {
        $out = [];
        $frontier = [$id];
        for ($i = 0; $i < 50 && $frontier !== []; $i++) {
            $rows = $this->db->select('SELECT id FROM product_categories WHERE parent_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')', $frontier);
            $frontier = array_values(array_diff(array_map(static fn (array $r): int => (int) $r['id'], $rows), $out, [$id]));
            array_push($out, ...$frontier);
        }
        return $out;
    }

    /** Ancestor ids, nearest first. @return list<int> */
    public function ancestorIds(int $id): array
    {
        $out = [];
        $current = $this->db->scalar('SELECT parent_id FROM product_categories WHERE id = ?', [$id]);
        while ($current !== null && !in_array((int) $current, $out, true) && count($out) < 50) {
            $out[] = (int) $current;
            $current = $this->db->scalar('SELECT parent_id FROM product_categories WHERE id = ?', [(int) $current]);
        }
        return $out;
    }

    /** "Parent › Child" label. */
    public function categoryPath(?int $id): string
    {
        if ($id === null) {
            return '';
        }
        $names = [];
        foreach (array_reverse(array_merge([$id], $this->ancestorIds($id))) as $cid) {
            $names[] = (string) $this->db->scalar('SELECT name FROM product_categories WHERE id = ?', [$cid]);
        }
        return implode(' › ', $names);
    }

    /**
     * Create or update a category. A parent can never be the category itself or one of its
     * descendants (that would create a loop), and nesting is limited to MAX_DEPTH levels.
     * @param array<string, mixed> $data @param array<string, mixed> $actor @param array{name: string, tmp_name: string, error: int, size: int}|null $image
     */
    public function saveCategory(array $data, array $actor, ?int $id = null, ?array $image = null, bool $httpUpload = true): int
    {
        $existing = $id !== null ? $this->category($id) : null;
        if ($id !== null && $existing === null) {
            throw new DomainRuleException('Category not found.');
        }
        if ($existing !== null && (int) $existing['is_archived'] === 1) {
            throw new DomainRuleException('Archived categories are read-only. Restore it first.');
        }
        $clean = $this->master($data, 'product_categories', $id, 150);
        $parent = (int) ($data['parent_id'] ?? 0) ?: null;
        if ($parent !== null) {
            $p = $this->category($parent);
            if ($p === null || (int) $p['is_archived'] === 1) {
                throw ValidationException::withMessage('parent_id', 'Choose an existing, non-archived parent category.');
            }
            if ($id !== null && ($parent === $id || in_array($parent, $this->descendantIds($id), true))) {
                throw ValidationException::withMessage('parent_id', 'A category cannot be placed under itself or one of its own sub-categories.');
            }
            $depth = count($this->ancestorIds($parent)) + 2 + ($id !== null ? $this->subtreeHeight($id) : 0);
            if ($depth > self::MAX_DEPTH) {
                throw ValidationException::withMessage('parent_id', 'Categories can be nested at most ' . self::MAX_DEPTH . ' levels deep.');
            }
        }
        $row = $clean + ['parent_id' => $parent, 'description' => $this->text($data, 'description', 500), 'display_order' => max(0, min(9999, (int) ($data['display_order'] ?? 0))),
            'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($image !== null && ($image['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $store = self::media();
            $info = $store->validate($image, $httpUpload, 'image', SecureFileStore::IMAGE_EXT);
            $saved = $store->save($image, $info['ext'], $httpUpload);
            $row += ['image_stored' => $saved['stored'], 'image_mime' => $info['mime']];
        }
        return $this->persist('product_categories', 'category', $row, $existing, $actor);
    }

    // ---------------------------------------------------------------- brands

    /** @return list<array<string, mixed>> */
    public function brands(bool $withArchived = false, bool $activeOnly = false): array
    {
        $where = [];
        if (!$withArchived) {
            $where[] = 'b.is_archived = 0';
        }
        if ($activeOnly) {
            $where[] = "b.status = 'active'";
        }
        return $this->db->select('SELECT b.*, (SELECT COUNT(*) FROM products p WHERE p.brand_id = b.id) AS product_count FROM product_brands b'
            . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY b.name');
    }

    /** @return array<string, mixed>|null */
    public function brand(int $id): ?array
    {
        return $this->db->first('SELECT * FROM product_brands WHERE id = ?', [$id]);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor @param array{name: string, tmp_name: string, error: int, size: int}|null $logo */
    public function saveBrand(array $data, array $actor, ?int $id = null, ?array $logo = null, bool $httpUpload = true): int
    {
        $existing = $id !== null ? $this->brand($id) : null;
        if ($id !== null && $existing === null) {
            throw new DomainRuleException('Brand not found.');
        }
        if ($existing !== null && (int) $existing['is_archived'] === 1) {
            throw new DomainRuleException('Archived brands are read-only. Restore it first.');
        }
        $clean = $this->master($data, 'product_brands', $id, 150);
        $website = trim((string) ($data['website'] ?? ''));
        if ($website !== '' && (filter_var($website, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $website))) {
            throw ValidationException::withMessage('website', 'Enter a full web address starting with https://');
        }
        $row = $clean + ['description' => $this->text($data, 'description', 500), 'website' => $website !== '' ? mb_substr($website, 0, 255) : null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($logo !== null && ($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $store = self::media();
            $info = $store->validate($logo, $httpUpload, 'logo', SecureFileStore::IMAGE_EXT);
            $saved = $store->save($logo, $info['ext'], $httpUpload);
            $row += ['logo_stored' => $saved['stored'], 'logo_mime' => $info['mime']];
        }
        return $this->persist('product_brands', 'brand', $row, $existing, $actor);
    }

    // ---------------------------------------------------------------- category & brand status

    /**
     * Activate, deactivate, archive or restore a category or brand. Masters used by products
     * are kept (archived), so existing products still show them; archived masters cannot be
     * chosen for new or edited products.
     * @param array<string, mixed> $actor
     */
    public function setMasterStatus(string $kind, int $id, string $action, ?string $reason, array $actor): void
    {
        $table = $kind === 'category' ? 'product_categories' : 'product_brands';
        $row = $this->db->first("SELECT * FROM {$table} WHERE id = ?", [$id]) ?? throw new DomainRuleException(ucfirst($kind) . ' not found.');
        $update = match ($action) {
            'activate' => ['status' => 'active'],
            'deactivate' => ['status' => 'inactive'],
            'archive' => ['is_archived' => 1, 'status' => 'inactive', 'archived_at' => now(), 'archived_by' => (int) $actor['id']],
            'restore' => ['is_archived' => 0, 'archived_at' => null, 'archived_by' => null],
            default => throw ValidationException::withMessage('action', 'Choose a valid action.'),
        };
        if (in_array($action, ['activate', 'deactivate'], true) && (int) $row['is_archived'] === 1) {
            throw new DomainRuleException('Restore the ' . $kind . ' first.');
        }
        if ($action === 'archive') {
            if ((int) $row['is_archived'] === 1) {
                throw new DomainRuleException('Already archived.');
            }
            if (trim((string) $reason) === '') {
                throw ValidationException::withMessage('reason', 'A reason is required.');
            }
            if ($kind === 'category' && $this->db->scalar('SELECT 1 FROM product_categories WHERE parent_id = ? AND is_archived = 0', [$id])) {
                throw new DomainRuleException('Archive or move its sub-categories first.');
            }
        }
        if ($action === 'restore' && $kind === 'category' && $row['parent_id'] !== null && (int) $this->db->scalar('SELECT is_archived FROM product_categories WHERE id = ?', [$row['parent_id']]) === 1) {
            throw new DomainRuleException('Restore its parent category first.');
        }
        $this->db->update($table, $update + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $id]);
        $used = (int) $this->db->scalar('SELECT COUNT(*) FROM products WHERE ' . ($kind === 'category' ? 'category_id = ? OR subcategory_id = ?' : 'brand_id = ?'), $kind === 'category' ? [$id, $id] : [$id]);
        App::activity()->log("product.{$kind}_{$action}d", ucfirst($action === 'activate' ? 'activated' : ($action === 'deactivate' ? 'deactivated' : $action . 'd')) . " {$kind} {$row['code']} ({$row['name']})", "product_{$kind}", $id,
            module: 'products', oldValues: ['status' => $row['status'], 'archived' => (int) $row['is_archived']], newValues: $update + ['reason' => $reason, 'products_using' => $used]);
    }

    // ---------------------------------------------------------------- attributes

    /** @return list<array<string, mixed>> with 'values' and usage counts */
    public function attributes(bool $activeOnly = false): array
    {
        $attrs = $this->db->select('SELECT a.*, (SELECT COUNT(DISTINCT va.variant_id) FROM product_variant_attributes va WHERE va.attribute_id = a.id) AS variant_count
            FROM product_attributes a' . ($activeOnly ? ' WHERE a.is_active = 1' : '') . ' ORDER BY a.sort_order, a.name');
        $values = $this->db->select('SELECT v.*, (SELECT COUNT(*) FROM product_variant_attributes va WHERE va.value_id = v.id) AS used FROM product_attribute_values v' . ($activeOnly ? ' WHERE v.is_active = 1' : '') . ' ORDER BY v.sort_order, v.value');
        $by = [];
        foreach ($values as $v) {
            $by[(int) $v['attribute_id']][] = $v;
        }
        foreach ($attrs as &$a) {
            $a['values'] = $by[(int) $a['id']] ?? [];
        }
        return $attrs;
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveAttribute(array $data, array $actor, ?int $id = null): int
    {
        $existing = $id !== null ? $this->db->first('SELECT * FROM product_attributes WHERE id = ?', [$id]) : null;
        if ($id !== null && $existing === null) {
            throw new DomainRuleException('Attribute not found.');
        }
        $clean = $this->master($data, 'product_attributes', $id, 100, false);
        $type = (string) ($data['input_type'] ?? ($existing['input_type'] ?? 'select'));
        if (!in_array($type, ['select', 'text', 'number'], true)) {
            throw ValidationException::withMessage('input_type', 'Choose how values are entered.');
        }
        if ($existing !== null && $type !== $existing['input_type'] && $this->db->scalar('SELECT 1 FROM product_variant_attributes WHERE attribute_id = ?', [$id])) {
            throw ValidationException::withMessage('input_type', 'Variants already use this attribute, so how its values are entered cannot change. Create a new attribute instead.');
        }
        $unit = trim((string) ($data['unit'] ?? ''));
        $row = ['code' => $clean['code'], 'name' => $clean['name'], 'input_type' => $type, 'unit' => $unit !== '' ? mb_substr($unit, 0, 20) : null,
            'description' => $this->text($data, 'description', 255), 'is_active' => !empty($data['is_active']) || $existing === null ? 1 : 0,
            'sort_order' => max(0, min(9999, (int) ($data['sort_order'] ?? 0))), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        return $this->persist('product_attributes', 'attribute', $row, $existing, $actor);
    }

    /** Add or update an allowed value of a select attribute. @param array<string, mixed> $actor */
    public function saveAttributeValue(int $attributeId, string $value, int $sort, bool $active, array $actor, ?int $valueId = null): int
    {
        $attr = $this->db->first('SELECT * FROM product_attributes WHERE id = ?', [$attributeId]) ?? throw new DomainRuleException('Attribute not found.');
        if ($attr['input_type'] !== 'select') {
            throw new DomainRuleException('Only list attributes have predefined values; text and number attributes are typed on each variant.');
        }
        $value = trim((string) preg_replace('/\s+/', ' ', $value));
        if ($value === '' || mb_strlen($value) > 100) {
            throw ValidationException::withMessage('value', 'Enter a value of up to 100 characters.');
        }
        $clash = $this->db->scalar('SELECT id FROM product_attribute_values WHERE attribute_id = ? AND LOWER(value) = ?' . ($valueId ? ' AND id <> ?' : ''), array_merge([$attributeId, mb_strtolower($value)], $valueId ? [$valueId] : []));
        if ($clash) {
            throw ValidationException::withMessage('value', "{$attr['name']} already has the value \"{$value}\".");
        }
        $row = ['value' => $value, 'sort_order' => max(0, min(9999, $sort)), 'is_active' => $active ? 1 : 0, 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($valueId !== null) {
            $old = $this->db->first('SELECT * FROM product_attribute_values WHERE id = ? AND attribute_id = ?', [$valueId, $attributeId]) ?? throw new DomainRuleException('Value not found.');
            $this->db->update('product_attribute_values', $row, ['id' => $valueId]);
            // Variants keep pointing at the value row, so renaming it renames it everywhere; the variant names stay as typed.
            App::activity()->log('product.attribute_value_updated', "Updated value \"{$old['value']}\" of attribute {$attr['name']}", 'product_attribute', $attributeId, module: 'products',
                oldValues: ['value' => $old['value'], 'active' => (int) $old['is_active']], newValues: ['value' => $value, 'active' => $active ? 1 : 0]);
            return $valueId;
        }
        $id = $this->db->insert('product_attribute_values', $row + ['attribute_id' => $attributeId, 'created_by' => (int) $actor['id'], 'created_at' => now()]);
        App::activity()->log('product.attribute_value_added', "Added value \"{$value}\" to attribute {$attr['name']}", 'product_attribute', $attributeId, module: 'products', newValues: ['value' => $value]);
        return $id;
    }

    // ---------------------------------------------------------------- product types

    /** @return list<array<string, mixed>> */
    public function productTypes(bool $activeOnly = true): array
    {
        return $this->db->select('SELECT t.*, (SELECT COUNT(*) FROM products p WHERE p.product_type_id = t.id) AS product_count FROM product_types t'
            . ($activeOnly ? ' WHERE t.is_active = 1' : '') . ' ORDER BY t.sort_order, t.name');
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveProductType(array $data, array $actor, ?int $id = null): int
    {
        $existing = $id !== null ? $this->db->first('SELECT * FROM product_types WHERE id = ?', [$id]) : null;
        if ($id !== null && $existing === null) {
            throw new DomainRuleException('Product type not found.');
        }
        $clean = $this->master($data, 'product_types', $id, 100, false);
        if ($existing !== null && (int) $existing['is_system'] === 1 && $clean['code'] !== $existing['code']) {
            throw ValidationException::withMessage('code', 'Built-in types keep their code.');
        }
        $row = ['code' => $clean['code'], 'name' => $clean['name'], 'description' => $this->text($data, 'description', 255),
            'is_service' => !empty($data['is_service']) ? 1 : 0, 'tracks_inventory' => !empty($data['tracks_inventory']) ? 1 : 0, 'has_components' => !empty($data['has_components']) ? 1 : 0,
            'is_active' => !empty($data['is_active']) || $existing === null ? 1 : 0, 'sort_order' => max(0, min(9999, (int) ($data['sort_order'] ?? 0))), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        if ($existing !== null && (int) $existing['has_components'] === 1 && $row['has_components'] === 0 && $this->db->scalar('SELECT 1 FROM product_bundle_items b JOIN products p ON p.id = b.bundle_product_id WHERE p.product_type_id = ?', [$id])) {
            throw ValidationException::withMessage('has_components', 'Products of this type already list components. Remove them first.');
        }
        return $this->persist('product_types', 'product_type', $row, $existing, $actor);
    }

    /** Category image or brand logo (any user who can view products). */
    public function imageResponse(string $kind, int $id): Response
    {
        $row = $kind === 'category' ? $this->category($id) : $this->brand($id);
        $stored = $row[$kind === 'category' ? 'image_stored' : 'logo_stored'] ?? null;
        if ($row === null || $stored === null) {
            throw new \App\Exceptions\HttpException(404, 'Image not found.');
        }
        return self::media()->response((string) $stored, (string) $row[$kind === 'category' ? 'image_mime' : 'logo_mime'], $row['code'] . '.img', true);
    }

    // ---------------------------------------------------------------- internals

    /**
     * Code + name (+ status) validation shared by the masters. Codes are upper case letters,
     * digits, - and _, unique per table.
     * @param array<string, mixed> $data @return array<string, mixed>
     */
    private function master(array $data, string $table, ?int $id, int $nameMax, bool $withStatus = true): array
    {
        $code = strtoupper(trim((string) ($data['code'] ?? '')));
        $name = trim((string) preg_replace('/\s+/', ' ', (string) ($data['name'] ?? '')));
        $v = Validator::make(['code' => $code, 'name' => $name], ['code' => 'required|regex:/^[A-Z0-9][A-Z0-9_-]{0,39}$/', 'name' => 'required|string|max:' . $nameMax],
            ['code' => 'Code', 'name' => 'Name']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if ($table === 'product_types' || $table === 'product_attributes') {
            $code = strtolower($code);
        }
        $clash = $this->db->scalar("SELECT 1 FROM {$table} WHERE LOWER(code) = ?" . ($id !== null ? ' AND id <> ?' : ''), array_merge([strtolower($code)], $id !== null ? [$id] : []));
        if ($clash) {
            throw ValidationException::withMessage('code', "The code {$code} is already used.");
        }
        $out = ['code' => $code, 'name' => $name];
        if ($withStatus) {
            $out['status'] = ($data['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        }
        return $out;
    }

    /** @param array<string, mixed> $data */
    private function text(array $data, string $key, int $max): ?string
    {
        $v = trim((string) ($data[$key] ?? ''));
        return $v !== '' ? mb_substr($v, 0, $max) : null;
    }

    private function subtreeHeight(int $id): int
    {
        $height = 0;
        $frontier = [$id];
        while ($frontier !== [] && $height < 50) {
            $frontier = array_map(static fn (array $r): int => (int) $r['id'], $this->db->select('SELECT id FROM product_categories WHERE parent_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')', $frontier));
            if ($frontier !== []) {
                $height++;
            }
        }
        return $height;
    }

    /** @param array<string, mixed> $row @param array<string, mixed>|null $existing @param array<string, mixed> $actor */
    private function persist(string $table, string $kind, array $row, ?array $existing, array $actor): int
    {
        $label = str_replace('_', ' ', $kind);
        if ($existing === null) {
            $id = $this->db->insert($table, $row + ['created_by' => (int) $actor['id'], 'created_at' => now()]);
            App::activity()->log("product.{$kind}_created", "Added {$label} {$row['code']} ({$row['name']})", "product_{$kind}", $id, module: 'products',
                newValues: array_diff_key($row, array_flip(['updated_by', 'updated_at'])));
            return $id;
        }
        $old = [];
        $new = [];
        foreach ($row as $k => $v) {
            if (in_array($k, ['updated_by', 'updated_at'], true)) {
                continue;
            }
            if ((string) ($existing[$k] ?? '') !== (string) ($v ?? '')) {
                $old[$k] = $existing[$k] ?? null;
                $new[$k] = $v;
            }
        }
        $this->db->update($table, $row, ['id' => $existing['id']]);
        if ($new !== []) {
            App::activity()->log("product.{$kind}_updated", "Updated {$label} {$row['code']} ({$row['name']})", "product_{$kind}", (int) $existing['id'], module: 'products', oldValues: $old, newValues: $new);
        }
        return (int) $existing['id'];
    }
}
