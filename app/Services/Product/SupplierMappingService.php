<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Product ↔ supplier mappings (many-to-many, optionally per variant) with supplier codes,
 * order quantities, lead time, priority and dates. Ending a mapping keeps it as history.
 *
 * Preferred supplier: an explicit choice (never "the cheapest"). With the single-preferred
 * rule on, marking one removes the mark from the previous one; the change goes through the
 * preferred-supplier workflow. Supplier purchase prices are product_prices rows (type
 * supplier_purchase) so all price history is in one place.
 */
final class SupplierMappingService
{
    public const STATUSES = ['active' => 'Active', 'inactive' => 'Inactive', 'ended' => 'Ended'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<array<string, mixed>> with the current supplier purchase price */
    public function forProduct(int $productId, bool $withEnded = true): array
    {
        return $this->withPrices($this->db->select($this->select() . ' WHERE m.product_id = ?' . ($withEnded ? '' : " AND m.status = 'active'")
            . " ORDER BY CASE m.status WHEN 'active' THEN 0 WHEN 'inactive' THEN 1 ELSE 2 END, m.is_preferred DESC, m.priority, s.name", [$productId]));
    }

    /** @param array{0: string, 1: list<mixed>}|null $productScope limit to products the user may see (alias p) @return list<array<string, mixed>> */
    public function forSupplier(int $supplierId, ?array $productScope = null, bool $withEnded = true): array
    {
        [$w, $p] = $productScope ?? ['1 = 1', []];
        return $this->withPrices($this->db->select($this->select() . " WHERE m.supplier_id = ? AND {$w}" . ($withEnded ? '' : " AND m.status = 'active'")
            . " ORDER BY CASE m.status WHEN 'active' THEN 0 WHEN 'inactive' THEN 1 ELSE 2 END, p.name, v.name", array_merge([$supplierId], $p)));
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first($this->select() . ' WHERE m.id = ?', [$id]);
    }

    /**
     * Link a supplier to a product (or one variant). A purchase price, when given, is added
     * as a supplier purchase price (through the price-change workflow).
     * @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{0: int, 1: array<string, mixed>|null, 2: array<string, mixed>|null} [mapping id, price row, preferred change request]
     */
    public function add(array $p, array $data, array $actor): array
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        $supplierId = (int) ($data['supplier_id'] ?? 0);
        $supplier = $this->db->first('SELECT id, supplier_code, name, status FROM suppliers WHERE id = ?', [$supplierId]);
        if ($supplier === null || !in_array($supplier['status'], ['active', 'inactive', 'draft', 'pending_approval'], true)) {
            throw ValidationException::withMessage('supplier_id', 'Choose a supplier that is not archived or blocked.');
        }
        $variantId = (int) ($data['variant_id'] ?? 0) ?: null;
        if ($variantId !== null && !$this->db->scalar("SELECT 1 FROM product_variants WHERE id = ? AND product_id = ? AND status <> 'archived'", [$variantId, $p['id']])) {
            throw ValidationException::withMessage('variant_id', 'Choose a variant of this product.');
        }
        if ($this->db->scalar("SELECT 1 FROM product_supplier_mappings WHERE product_id = ? AND supplier_id = ? AND status <> 'ended' AND " . ($variantId === null ? 'variant_id IS NULL' : 'variant_id = ?'),
            array_merge([$p['id'], $supplierId], $variantId === null ? [] : [$variantId]))) {
            throw new DomainRuleException("{$supplier['name']} is already linked to this " . ($variantId ? 'variant' : 'product') . '. Edit that mapping instead.');
        }
        $clean = $this->validate($data);
        $from = trim((string) ($data['effective_from'] ?? '')) ?: BusinessDate::today();
        if (!BusinessDate::isValid($from)) {
            throw ValidationException::withMessage('effective_from', 'Enter a valid start date.');
        }
        $price = trim((string) ($data['purchase_price'] ?? ''));
        if ($price !== '' && !App::gate()->allows($actor, 'products.manage_pricing')) {
            throw new \App\Exceptions\AuthorizationException('products.manage_pricing', 'You may link suppliers but not record purchase prices.');
        }
        $id = $this->db->insert('product_supplier_mappings', $clean + [
            'product_id' => (int) $p['id'], 'variant_id' => $variantId, 'supplier_id' => $supplierId, 'is_preferred' => 0, 'effective_from' => $from, 'status' => 'active',
            'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $values = $clean + ['supplier' => $supplier['supplier_code'] . ' ' . $supplier['name'], 'variant_id' => $variantId, 'from' => $from];
        $this->record($p, $supplier, 'supplier_linked', null, $values + ['mapping' => $id], null, $actor, $variantId, "Linked supplier {$supplier['supplier_code']} to {$p['product_code']}");
        $priceRow = null;
        if ($price !== '') {
            $priceRow = (new PricingService($this->db))->add($p, ['price_type' => 'supplier_purchase', 'amount' => $price, 'supplier_id' => $supplierId, 'variant_id' => $variantId,
                'currency' => $data['currency'] ?? 'INR', 'effective_from' => $from], $actor);
        }
        $pref = !empty($data['is_preferred']) ? $this->requestPreferred($p, (int) $id, 'Preferred when the supplier was linked', $actor) : null;
        return [(int) $id, $priceRow, $pref];
    }

    /** Update codes, quantities, lead time, priority and notes. @param array<string, mixed> $p @param array<string, mixed> $m @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function update(array $p, array $m, array $data, array $actor): void
    {
        if ($p['status'] === 'archived' || $m['status'] === 'ended') {
            throw new DomainRuleException('Ended mappings and archived products are read-only.');
        }
        $clean = $this->validate($data);
        $status = ($data['status'] ?? $m['status']) === 'inactive' ? 'inactive' : 'active';
        $old = [];
        $new = [];
        foreach ($clean + ['status' => $status] as $k => $v) {
            $same = in_array($k, ['min_order_qty', 'max_order_qty'], true) ? (float) ($m[$k] ?? 0) === (float) ($v ?? 0) && ($m[$k] === null) === ($v === null) : (string) ($m[$k] ?? '') === (string) ($v ?? '');
            if (!$same) {
                $old[$k] = $m[$k];
                $new[$k] = $v;
            }
        }
        if ($new === []) {
            return;
        }
        if ($status === 'inactive' && (int) $m['is_preferred'] === 1) {
            throw new DomainRuleException('This is the preferred supplier. Choose another preferred supplier before deactivating it.');
        }
        $this->db->update('product_supplier_mappings', $clean + ['status' => $status, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $m['id']]);
        $this->record($p, ['supplier_code' => $m['supplier_code'], 'name' => $m['supplier_name'], 'id' => $m['supplier_id']], 'supplier_mapping_updated', $old, $new + ['mapping' => (int) $m['id']], null, $actor,
            $m['variant_id'] !== null ? (int) $m['variant_id'] : null, "Updated supplier mapping {$m['supplier_code']} of {$p['product_code']}");
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $m @param array<string, mixed> $actor */
    public function end(array $p, array $m, ?string $date, string $reason, array $actor): void
    {
        if ($m['status'] === 'ended') {
            throw new DomainRuleException('This mapping has already ended.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $date = BusinessDate::isValid((string) $date) ? (string) $date : BusinessDate::today();
        if ($date < $m['effective_from']) {
            throw ValidationException::withMessage('effective_to', 'The end date cannot be before the start date.');
        }
        $this->db->update('product_supplier_mappings', ['status' => 'ended', 'is_preferred' => 0, 'effective_to' => $date, 'end_reason' => mb_substr($reason, 0, 255), 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $m['id']]);
        $this->record($p, ['supplier_code' => $m['supplier_code'], 'name' => $m['supplier_name'], 'id' => $m['supplier_id']], 'supplier_unlinked', ['status' => $m['status'], 'preferred' => (int) $m['is_preferred']],
            ['status' => 'ended', 'to' => $date, 'mapping' => (int) $m['id']], $reason, $actor, $m['variant_id'] !== null ? (int) $m['variant_id'] : null, "Ended supplier mapping {$m['supplier_code']} of {$p['product_code']}");
    }

    /**
     * Ask to make a mapping the preferred supplier of its product / variant. Applies at once
     * when the preferred-supplier workflow is inactive.
     * @param array<string, mixed> $p @param array<string, mixed> $actor @return array<string, mixed> change request
     */
    public function requestPreferred(array $p, int $mappingId, string $reason, array $actor): array
    {
        $m = $this->find($mappingId);
        if ($m === null || (int) $m['product_id'] !== (int) $p['id']) {
            throw new DomainRuleException('Mapping not found.');
        }
        if ($m['status'] !== 'active') {
            throw new DomainRuleException('Only an active mapping can be the preferred supplier.');
        }
        if ((int) $m['is_preferred'] === 1) {
            throw new DomainRuleException("{$m['supplier_name']} is already the preferred supplier.");
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'Give a reason for the change.');
        }
        $products = new ProductService($this->db);
        if ($products->pendingOfType((int) $p['id'], 'preferred_supplier') !== null) {
            throw new DomainRuleException('A preferred-supplier change for this product is already waiting for approval.');
        }
        $current = $this->preferredFor((int) $p['id'], $m['variant_id'] !== null ? (int) $m['variant_id'] : null);
        return $products->request($p, 'preferred_supplier', $current !== null ? ['mapping' => (int) $current['id'], 'supplier' => $current['supplier_name']] : null,
            ['mapping' => $mappingId, 'supplier' => $m['supplier_name'], 'variant_id' => $m['variant_id']], $reason, $actor, 'products.manage_supplier_mappings', $m['variant_id'] !== null ? (int) $m['variant_id'] : null);
    }

    /** Called by ProductService when a preferred-supplier request is decided. @param array<string, mixed> $p @param array<string, mixed> $change @param array<string, mixed> $by */
    public function decidePreferred(array $p, array $change, string $status, array $by): void
    {
        if ($status !== 'approved') {
            return;
        }
        $m = $this->find((int) $change['new_values']['mapping']);
        if ($m === null || $m['status'] !== 'active') {
            throw new DomainRuleException('The supplier mapping is no longer active, so it cannot become preferred.');
        }
        $variantId = $m['variant_id'] !== null ? (int) $m['variant_id'] : null;
        if (setting('product_single_preferred_supplier', true)) {
            $this->db->execute('UPDATE product_supplier_mappings SET is_preferred = 0, updated_at = ? WHERE product_id = ? AND is_preferred = 1 AND ' . ($variantId === null ? 'variant_id IS NULL' : 'variant_id = ?'),
                array_merge([now(), $p['id']], $variantId === null ? [] : [$variantId]));
        }
        $this->db->update('product_supplier_mappings', ['is_preferred' => 1, 'updated_by' => $by['id'], 'updated_at' => now()], ['id' => $m['id']]);
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $p['id'], 'preferred_supplier', null, null, $change['old_values'], $change['new_values'], $change['reason'], null, $by, $variantId);
    }

    /** @return array<string, mixed>|null */
    public function preferredFor(int $productId, ?int $variantId): ?array
    {
        return $this->db->first($this->select() . " WHERE m.product_id = ? AND m.is_preferred = 1 AND m.status = 'active' AND " . ($variantId === null ? 'm.variant_id IS NULL' : 'm.variant_id = ?') . ' LIMIT 1',
            array_merge([$productId], $variantId === null ? [] : [$variantId]));
    }

    // ---------------------------------------------------------------- internals

    private function select(): string
    {
        return 'SELECT m.*, s.supplier_code, s.name AS supplier_name, s.status AS supplier_status, p.product_code, p.name AS product_name, p.sku AS product_sku, p.status AS product_status,
                v.name AS variant_name, v.sku AS variant_sku, cu.name AS created_by_name
            FROM product_supplier_mappings m JOIN suppliers s ON s.id = m.supplier_id JOIN products p ON p.id = m.product_id
            LEFT JOIN product_variants v ON v.id = m.variant_id LEFT JOIN users cu ON cu.id = m.created_by';
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function withPrices(array $rows): array
    {
        $pricing = new PricingService($this->db);
        $today = BusinessDate::today();
        foreach ($rows as &$r) {
            $price = $pricing->priceOn((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, 'supplier_purchase', $today, (int) $r['supplier_id']);
            $r['purchase_price'] = $price !== null ? Money::fromDb($price['amount']) : null;
            $r['purchase_currency'] = $price['currency'] ?? null;
        }
        return $rows;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function validate(array $data): array
    {
        $qty = static function (string $k) use ($data): ?string {
            $v = str_replace(',', '', trim((string) ($data[$k] ?? '')));
            if ($v === '') {
                return null;
            }
            if (preg_match('/^\d{1,9}(\.\d{1,3})?$/', $v) !== 1 || (float) $v <= 0) {
                throw ValidationException::withMessage($k, 'Enter a quantity greater than zero (up to 3 decimals).');
            }
            return $v;
        };
        $min = $qty('min_order_qty');
        $max = $qty('max_order_qty');
        if ($min !== null && $max !== null && (float) $max < (float) $min) {
            throw ValidationException::withMessage('max_order_qty', 'The maximum order quantity cannot be below the minimum.');
        }
        $lead = trim((string) ($data['lead_time_days'] ?? ''));
        if ($lead !== '' && (preg_match('/^\d{1,3}$/', $lead) !== 1 || (int) $lead > 365)) {
            throw ValidationException::withMessage('lead_time_days', 'Lead time is a number of days between 0 and 365.');
        }
        $priority = (int) ($data['priority'] ?? 10);
        if ($priority < 1 || $priority > 99) {
            throw ValidationException::withMessage('priority', 'Priority is between 1 (first choice) and 99.');
        }
        $text = static fn (string $k, int $max): ?string => ($v = trim((string) ($data[$k] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
        return ['supplier_product_code' => $text('supplier_product_code', 80), 'supplier_sku' => $text('supplier_sku', 80), 'min_order_qty' => $min, 'max_order_qty' => $max,
            'lead_time_days' => $lead !== '' ? (int) $lead : null, 'priority' => $priority, 'notes' => $text('notes', 500)];
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $s @param array<string, mixed>|null $old @param array<string, mixed> $new @param array<string, mixed> $actor */
    private function record(array $p, array $s, string $event, ?array $old, array $new, ?string $reason, array $actor, ?int $variantId, string $text): void
    {
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $p['id'], $event, null, null, $old, $new, $reason, null, $actor, $variantId);
        HistoryWriter::write($this->db, 'supplier_history', 'supplier_id', (int) $s['id'], 'product_' . $event, null, null, $old, $new + ['product' => $p['product_code'] . ' ' . $p['name']], $reason, null, $actor);
        App::activity()->log('product.' . $event, $text, 'product', (int) $p['id'], module: 'products', oldValues: $old, newValues: $new + ($reason !== null ? ['reason' => $reason] : []));
    }
}
