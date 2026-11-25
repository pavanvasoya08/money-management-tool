<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\Barcode;
use App\Support\Uuid;

/**
 * Product variants and bundle / kit components.
 *
 * Variants store one value per attribute (product_variant_attributes) — no fixed size or
 * colour columns. Each attribute combination exists once per product (attribute_signature),
 * every variant has its own UUID and code (the stable identifier stock will use in Phase 7)
 * and its own SKU in the shared SKU registry. Variants are archived, never deleted.
 */
final class VariantService
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued', 'archived' => 'Archived'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> with 'attributes' (list of [attribute, value]) */
    public function forProduct(int $productId, bool $withArchived = true): array
    {
        $rows = $this->db->select("SELECT v.*, (SELECT i.id FROM product_images i WHERE i.variant_id = v.id AND i.status = 'active' ORDER BY i.is_primary DESC, i.id LIMIT 1) AS image_id
            FROM product_variants v WHERE v.product_id = ?" . ($withArchived ? '' : " AND v.status <> 'archived'") . ' ORDER BY v.sort_order, v.id', [$productId]);
        $attrs = $this->attributeRows(array_map(static fn (array $r): int => (int) $r['id'], $rows));
        foreach ($rows as &$r) {
            $r['attributes'] = $attrs[(int) $r['id']] ?? [];
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function find(int $productId, int $variantId): ?array
    {
        $r = $this->db->first('SELECT * FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $productId]);
        if ($r !== null) {
            $r['attributes'] = $this->attributeRows([$variantId])[$variantId] ?? [];
        }
        return $r;
    }

    /** Attribute values of a variant, in attribute order (for {ATTR} SKUs). @return list<string> */
    public function valueLabels(int $variantId): array
    {
        return array_map(static fn (array $a): string => (string) $a['value'], $this->attributeRows([$variantId])[$variantId] ?? []);
    }

    /**
     * @param array<string, mixed> $p product @param array<string, mixed> $data name, sku, barcode, attributes[attrId] => valueId|text, sort_order
     * @param array<string, mixed> $actor
     */
    public function create(array $p, array $data, array $actor): int
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        [$name, $barcode, $attrs, $signature] = $this->validate($p, $data, null);
        $skus = new SkuService($this->db);
        $sku = SkuService::normalize((string) ($data['sku'] ?? ''));
        if ($sku !== '') {
            if (!setting('product_sku_manual_allowed', true)) {
                throw ValidationException::withMessage('sku', 'SKUs are generated automatically. Leave the SKU empty.');
            }
            $skus->assertAvailable($sku);
        }
        return $this->db->transaction(function (Database $db) use ($p, $name, $barcode, $attrs, $signature, $sku, $skus, $data, $actor): int {
            $generated = false;
            if ($sku === '' && setting('product_sku_auto_generate', true)) {
                $sku = $skus->generate((string) setting('product_variant_sku_pattern', '{PARENT}-{SEQ:2}'), $skus->variantTokens($p, array_column($attrs, 'label')));
                $generated = true;
            }
            $id = $db->insert('product_variants', [
                'uuid' => Uuid::v4(), 'product_id' => (int) $p['id'], 'variant_code' => $this->nextCode($p), 'name' => $name, 'sku' => $sku !== '' ? $sku : null, 'barcode' => $barcode,
                'attribute_signature' => $signature, 'status' => 'active', 'sort_order' => max(0, min(9999, (int) ($data['sort_order'] ?? 0))),
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->saveAttributes($id, $attrs);
            if ($sku !== '') {
                $skus->register($sku, (int) $p['id'], $id, $generated, (int) $actor['id']);
            }
            $db->update('products', ['has_variants' => 1, 'updated_at' => now()], ['id' => $p['id']]);
            $values = ['name' => $name, 'sku' => $sku ?: null, 'barcode' => $barcode, 'attributes' => array_column($attrs, 'label', 'attribute')];
            HistoryWriter::write($db, 'product_history', 'product_id', (int) $p['id'], 'variant_added', null, 'active', null, $values, null, null, $actor, $id);
            App::activity()->log('product.variant_created', "Added variant \"{$name}\" to {$p['product_code']}", 'product', (int) $p['id'], module: 'products', newValues: $values + ['variant_id' => $id]);
            return $id;
        });
    }

    /** Update name, barcode, attributes and order (SKU changes go through ProductService::requestSku). @param array<string, mixed> $p @param array<string, mixed> $v @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function update(array $p, array $v, array $data, array $actor): void
    {
        if ($p['status'] === 'archived' || $v['status'] === 'archived') {
            throw new DomainRuleException('Archived products and variants are read-only.');
        }
        [$name, $barcode, $attrs, $signature] = $this->validate($p, $data, (int) $v['id']);
        $old = ['name' => $v['name'], 'barcode' => $v['barcode'], 'attributes' => array_column($v['attributes'], 'value', 'attribute')];
        $new = ['name' => $name, 'barcode' => $barcode, 'attributes' => array_column($attrs, 'label', 'attribute')];
        $this->db->transaction(function (Database $db) use ($v, $name, $barcode, $signature, $attrs, $data, $actor): void {
            $db->update('product_variants', ['name' => $name, 'barcode' => $barcode, 'attribute_signature' => $signature, 'sort_order' => max(0, min(9999, (int) ($data['sort_order'] ?? $v['sort_order']))),
                'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $v['id']]);
            $db->execute('DELETE FROM product_variant_attributes WHERE variant_id = ?', [$v['id']]); // the variant's current attribute set (history keeps the old one)
            $this->saveAttributes((int) $v['id'], $attrs);
        });
        if ($old != $new) {
            HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $p['id'], 'variant_updated', null, null, $old, $new, null, null, $actor, (int) $v['id']);
            App::activity()->log('product.variant_updated', "Updated variant \"{$name}\" of {$p['product_code']}", 'product', (int) $p['id'], module: 'products', oldValues: $old, newValues: $new + ['variant_id' => (int) $v['id']]);
        }
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $v @param array<string, mixed> $actor */
    public function setStatus(array $p, array $v, string $to, ?string $reason, array $actor): void
    {
        if (!isset(self::STATUSES[$to]) || $to === $v['status']) {
            throw ValidationException::withMessage('status', 'Choose a different status.');
        }
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        if ($to !== 'active' && trim((string) $reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('product_variants', ['status' => $to, 'archived_at' => $to === 'archived' ? now() : null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $v['id']]);
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $p['id'], 'variant_status', $v['status'], $to, null, null, $reason, null, $actor, (int) $v['id']);
        App::activity()->log('product.variant_status', "Variant \"{$v['name']}\" of {$p['product_code']}: {$v['status']} → {$to}", 'product', (int) $p['id'], module: 'products',
            oldValues: ['status' => $v['status']], newValues: ['status' => $to, 'variant_id' => (int) $v['id'], 'reason' => $reason]);
    }

    // ---------------------------------------------------------------- bundle / kit components

    /** @return list<array<string, mixed>> */
    public function components(int $productId): array
    {
        return $this->db->select('SELECT b.*, p.product_code, p.name AS component_name, p.sku AS component_sku, p.status AS component_status, v.name AS variant_name, v.sku AS variant_sku
            FROM product_bundle_items b JOIN products p ON p.id = b.component_product_id LEFT JOIN product_variants v ON v.id = b.component_variant_id
            WHERE b.bundle_product_id = ? ORDER BY b.id', [$productId]);
    }

    /** @return list<array<string, mixed>> bundles / kits that contain the product */
    public function usedIn(int $productId): array
    {
        return $this->db->select('SELECT b.quantity, p.id, p.product_code, p.name, p.status FROM product_bundle_items b JOIN products p ON p.id = b.bundle_product_id WHERE b.component_product_id = ? ORDER BY p.name', [$productId]);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function addComponent(array $p, int $componentId, ?int $variantId, string $quantity, ?string $notes, array $actor): int
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        if (!(int) $p['has_components']) {
            throw new DomainRuleException('Only bundle and kit product types list components.');
        }
        if ($componentId === (int) $p['id']) {
            throw ValidationException::withMessage('component_product_id', 'A product cannot contain itself.');
        }
        $c = $this->db->first('SELECT id, product_code, name, status FROM products WHERE id = ?', [$componentId]);
        if ($c === null || in_array($c['status'], ['archived'], true)) {
            throw ValidationException::withMessage('component_product_id', 'Choose a product that is not archived.');
        }
        if (in_array((int) $p['id'], $this->allComponentIds($componentId), true)) {
            throw ValidationException::withMessage('component_product_id', "{$c['name']} already contains this product, so it cannot also be one of its components.");
        }
        if ($variantId !== null && !$this->db->scalar("SELECT 1 FROM product_variants WHERE id = ? AND product_id = ? AND status <> 'archived'", [$variantId, $componentId])) {
            throw ValidationException::withMessage('component_variant_id', 'Choose a variant of the selected product.');
        }
        $q = str_replace(',', '', trim($quantity));
        if (preg_match('/^\d{1,9}(\.\d{1,3})?$/', $q) !== 1 || (float) $q <= 0) {
            throw ValidationException::withMessage('quantity', 'Enter a quantity greater than zero (up to 3 decimals).');
        }
        if ($this->db->scalar('SELECT 1 FROM product_bundle_items WHERE bundle_product_id = ? AND component_product_id = ? AND ' . ($variantId === null ? 'component_variant_id IS NULL' : 'component_variant_id = ?'),
            array_merge([$p['id'], $componentId], $variantId === null ? [] : [$variantId]))) {
            throw new DomainRuleException('This component is already listed. Change its quantity by removing and adding it again.');
        }
        $id = $this->db->insert('product_bundle_items', ['bundle_product_id' => (int) $p['id'], 'component_product_id' => $componentId, 'component_variant_id' => $variantId, 'quantity' => $q,
            'notes' => $notes !== null && trim($notes) !== '' ? mb_substr(trim($notes), 0, 255) : null, 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()]);
        $values = ['component' => $c['product_code'] . ' ' . $c['name'], 'variant_id' => $variantId, 'quantity' => $q];
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $p['id'], 'component_added', null, null, null, $values, null, null, $actor);
        App::activity()->log('product.component_added', "Added component {$c['product_code']} × {$q} to {$p['product_code']}", 'product', (int) $p['id'], module: 'products', newValues: $values);
        return $id;
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function removeComponent(array $p, int $itemId, string $reason, array $actor): void
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        $item = $this->db->first('SELECT b.*, c.product_code, c.name FROM product_bundle_items b JOIN products c ON c.id = b.component_product_id WHERE b.id = ? AND b.bundle_product_id = ?', [$itemId, $p['id']])
            ?? throw new DomainRuleException('Component not found.');
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        // Components describe the current make-up only; the removal is kept in the product history.
        $this->db->delete('product_bundle_items', ['id' => $itemId]);
        $values = ['component' => $item['product_code'] . ' ' . $item['name'], 'variant_id' => $item['component_variant_id'], 'quantity' => $item['quantity']];
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $p['id'], 'component_removed', null, null, $values, null, $reason, null, $actor);
        App::activity()->log('product.component_removed', "Removed component {$item['product_code']} from {$p['product_code']}", 'product', (int) $p['id'], module: 'products', oldValues: $values, newValues: ['reason' => $reason]);
    }

    // ---------------------------------------------------------------- internals

    /**
     * @param array<string, mixed> $p @param array<string, mixed> $data
     * @return array{0: string, 1: ?string, 2: list<array{attribute_id: int, attribute: string, value_id: ?int, text: ?string, label: string}>, 3: ?string}
     */
    private function validate(array $p, array $data, ?int $variantId): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', (string) ($data['name'] ?? '')));
        $attrs = [];
        $parts = [];
        foreach ((array) ($data['attributes'] ?? []) as $attrId => $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            $a = $this->db->first('SELECT * FROM product_attributes WHERE id = ?', [(int) $attrId]);
            if ($a === null) {
                continue;
            }
            if ($a['input_type'] === 'select') {
                $val = $this->db->first('SELECT * FROM product_attribute_values WHERE id = ? AND attribute_id = ?', [(int) $raw, (int) $attrId]);
                if ($val === null) {
                    throw ValidationException::withMessage('attributes', "Choose a listed value for {$a['name']}.");
                }
                $attrs[] = ['attribute_id' => (int) $a['id'], 'attribute' => $a['name'], 'value_id' => (int) $val['id'], 'text' => null, 'label' => (string) $val['value']];
                $parts[(int) $a['id']] = 'v' . (int) $val['id'];
            } else {
                if ($a['input_type'] === 'number' && preg_match('/^\d{1,9}(\.\d{1,3})?$/', $raw) !== 1) {
                    throw ValidationException::withMessage('attributes', "{$a['name']} must be a number.");
                }
                $text = mb_substr($raw, 0, 150);
                $attrs[] = ['attribute_id' => (int) $a['id'], 'attribute' => $a['name'], 'value_id' => null, 'text' => $text, 'label' => $text . ($a['unit'] ? ' ' . $a['unit'] : '')];
                $parts[(int) $a['id']] = 't' . mb_strtolower($text);
            }
        }
        if ($name === '') {
            $name = implode(' / ', array_column($attrs, 'label'));
        }
        if ($name === '' || mb_strlen($name) > 200) {
            throw ValidationException::withMessage('name', 'Give the variant a name or choose its attributes.');
        }
        if ($this->db->scalar('SELECT 1 FROM product_variants WHERE product_id = ? AND LOWER(name) = ?' . ($variantId ? ' AND id <> ?' : ''), array_merge([$p['id'], mb_strtolower($name)], $variantId ? [$variantId] : []))) {
            throw ValidationException::withMessage('name', "This product already has a variant called \"{$name}\".");
        }
        ksort($parts);
        $signature = $parts === [] ? null : mb_substr(implode(';', array_map(static fn ($k, $v): string => $k . '=' . $v, array_keys($parts), $parts)), 0, 255);
        if ($signature !== null && ($clash = $this->db->scalar('SELECT name FROM product_variants WHERE product_id = ? AND attribute_signature = ?' . ($variantId ? ' AND id <> ?' : ''),
            array_merge([$p['id'], $signature], $variantId ? [$variantId] : [])))) {
            throw ValidationException::withMessage('attributes', "The variant \"{$clash}\" already has exactly these attribute values.");
        }
        $barcode = trim((string) ($data['barcode'] ?? '')) ?: null;
        if ($barcode !== null) {
            if (($err = Barcode::error($barcode, Barcode::detect($barcode))) !== null) {
                throw ValidationException::withMessage('barcode', $err);
            }
            $clash = $this->db->scalar('SELECT product_code FROM products WHERE barcode = ?', [$barcode])
                ?: $this->db->scalar('SELECT p.product_code FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.barcode = ? AND v.id <> ?', [$barcode, $variantId ?? 0]);
            if ($clash) {
                throw ValidationException::withMessage('barcode', "This barcode is already used by {$clash}.");
            }
        }
        return [$name, $barcode, $attrs, $signature];
    }

    /** @param list<array{attribute_id: int, value_id: ?int, text: ?string}> $attrs */
    private function saveAttributes(int $variantId, array $attrs): void
    {
        foreach ($attrs as $a) {
            $this->db->insert('product_variant_attributes', ['variant_id' => $variantId, 'attribute_id' => $a['attribute_id'], 'value_id' => $a['value_id'], 'value_text' => $a['text'],
                'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param list<int> $variantIds @return array<int, list<array{attribute: string, value: string}>> */
    private function attributeRows(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $rows = $this->db->select('SELECT va.variant_id, a.id AS attribute_id, a.name AS attribute, a.unit, COALESCE(v.value, va.value_text) AS value, va.value_id
            FROM product_variant_attributes va JOIN product_attributes a ON a.id = va.attribute_id LEFT JOIN product_attribute_values v ON v.id = va.value_id
            WHERE va.variant_id IN (' . implode(',', array_fill(0, count($variantIds), '?')) . ') ORDER BY a.sort_order, a.name', $variantIds);
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['variant_id']][] = ['attribute_id' => (int) $r['attribute_id'], 'attribute' => $r['attribute'], 'value' => $r['value'] . ($r['value_id'] === null && $r['unit'] ? ' ' . $r['unit'] : ''),
                'value_id' => $r['value_id'] !== null ? (int) $r['value_id'] : null, 'raw' => $r['value']];
        }
        return $out;
    }

    /** Every product inside a bundle, recursively. @return list<int> */
    private function allComponentIds(int $productId): array
    {
        $seen = [];
        $frontier = [$productId];
        for ($i = 0; $i < 20 && $frontier !== []; $i++) {
            $rows = $this->db->select('SELECT component_product_id FROM product_bundle_items WHERE bundle_product_id IN (' . implode(',', array_fill(0, count($frontier), '?')) . ')', $frontier);
            $frontier = array_values(array_diff(array_map(static fn (array $r): int => (int) $r['component_product_id'], $rows), $seen));
            array_push($seen, ...$frontier);
        }
        return $seen;
    }

    /** @param array<string, mixed> $p */
    private function nextCode(array $p): string
    {
        $n = (int) $this->db->scalar('SELECT COUNT(*) FROM product_variants WHERE product_id = ?', [$p['id']]) + 1;
        do {
            $code = $p['product_code'] . '-V' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $n++;
        } while ($this->db->scalar('SELECT 1 FROM product_variants WHERE variant_code = ?', [$code]));
        return $code;
    }
}
