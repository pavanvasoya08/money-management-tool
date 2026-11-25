<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Product prices with full history.
 *
 * Each price row applies to one "slot": product, variant, price type, supplier, GST
 * registration, sales channel and currency. Rows are never edited once approved: a new
 * price closes the previous one the day before it starts (effective_until) and marks it
 * superseded, so the price that applied on any date can always be read back (priceOn).
 * New prices wait for approval when the price-change workflow is active. Later phases
 * (orders, purchases) store the price row id and copy the amount.
 */
final class PricingService
{
    public const SUBJECT = 'product_price';
    public const TYPES = [
        'standard_purchase' => 'Standard purchase price', 'standard_selling' => 'Standard selling price', 'minimum_selling' => 'Minimum selling price',
        'maximum_selling' => 'Maximum selling price (MRP)', 'wholesale' => 'Wholesale price', 'retail' => 'Retail price',
        'marketplace_selling' => 'Marketplace selling price', 'supplier_purchase' => 'Supplier purchase price',
    ];
    public const PURCHASE_TYPES = ['standard_purchase', 'supplier_purchase'];
    public const STATUSES = ['pending_approval' => 'Pending approval', 'active' => 'Active', 'superseded' => 'Superseded', 'cancelled' => 'Cancelled', 'rejected' => 'Rejected'];
    /** Selling prices that may not go below the minimum or above the maximum selling price. */
    private const BOUNDED = ['standard_selling', 'wholesale', 'retail', 'marketplace_selling'];
    private static bool $listening = false;

    public function __construct(private readonly Database $db)
    {
    }

    public static function registerApprovalListener(): void
    {
        if (self::$listening) {
            return;
        }
        self::$listening = true;
        ApprovalEngine::onDecision(self::SUBJECT, static function (array $request, string $status): void {
            (new self(App::db()))->applyDecision($request, $status);
        });
    }

    /** Field-rule key covering a price type. */
    public static function field(string $type): string
    {
        return in_array($type, self::PURCHASE_TYPES, true) ? 'purchase_cost' : 'selling_price';
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT pr.*, p.product_code, p.name AS product_name, v.name AS variant_name, s.name AS supplier_name, s.supplier_code, g.gstin
            FROM product_prices pr JOIN products p ON p.id = pr.product_id LEFT JOIN product_variants v ON v.id = pr.variant_id
            LEFT JOIN suppliers s ON s.id = pr.supplier_id LEFT JOIN gst_registrations g ON g.id = pr.gst_registration_id WHERE pr.id = ?', [$id]);
    }

    /**
     * Price history of a product (all versions), newest first.
     * @param array<string, mixed> $f type, variant, supplier, status
     * @return list<array<string, mixed>>
     */
    public function history(int $productId, array $f = []): array
    {
        $sql = ['pr.product_id = ?'];
        $params = [$productId];
        foreach (['type' => 'pr.price_type = ?', 'status' => 'pr.status = ?'] as $k => $c) {
            if (($f[$k] ?? '') !== '') {
                $sql[] = $c;
                $params[] = (string) $f[$k];
            }
        }
        foreach (['variant' => 'pr.variant_id = ?', 'supplier' => 'pr.supplier_id = ?'] as $k => $c) {
            if ((int) ($f[$k] ?? 0) > 0) {
                $sql[] = $c;
                $params[] = (int) $f[$k];
            }
        }
        return $this->db->select('SELECT pr.*, v.name AS variant_name, s.name AS supplier_name, g.gstin, cu.name AS created_by_name, du.name AS decided_by_name
            FROM product_prices pr LEFT JOIN product_variants v ON v.id = pr.variant_id LEFT JOIN suppliers s ON s.id = pr.supplier_id
            LEFT JOIN gst_registrations g ON g.id = pr.gst_registration_id LEFT JOIN users cu ON cu.id = pr.created_by LEFT JOIN users du ON du.id = pr.decided_by
            WHERE ' . implode(' AND ', $sql) . ' ORDER BY pr.effective_from DESC, pr.id DESC', $params);
    }

    /** Prices in force on a date (default today), one per slot. @return list<array<string, mixed>> */
    public function current(int $productId, ?string $date = null): array
    {
        $date ??= BusinessDate::today();
        return $this->db->select("SELECT pr.*, v.name AS variant_name, s.name AS supplier_name, s.supplier_code, g.gstin FROM product_prices pr
            LEFT JOIN product_variants v ON v.id = pr.variant_id LEFT JOIN suppliers s ON s.id = pr.supplier_id LEFT JOIN gst_registrations g ON g.id = pr.gst_registration_id
            WHERE pr.product_id = ? AND pr.status IN ('active', 'superseded') AND pr.effective_from <= ? AND (pr.effective_until IS NULL OR pr.effective_until >= ?)
            ORDER BY CASE WHEN pr.variant_id IS NULL THEN 0 ELSE 1 END, v.sort_order, v.name, pr.price_type, s.name, g.gstin", [$productId, $date, $date]);
    }

    /** Scheduled (approved, starting after today) and pending prices. @return list<array<string, mixed>> */
    public function upcoming(int $productId): array
    {
        return $this->db->select("SELECT pr.*, v.name AS variant_name, s.name AS supplier_name, g.gstin FROM product_prices pr LEFT JOIN product_variants v ON v.id = pr.variant_id
            LEFT JOIN suppliers s ON s.id = pr.supplier_id LEFT JOIN gst_registrations g ON g.id = pr.gst_registration_id
            WHERE pr.product_id = ? AND (pr.status = 'pending_approval' OR (pr.status = 'active' AND pr.effective_from > ?)) ORDER BY pr.effective_from, pr.id", [$productId, BusinessDate::today()]);
    }

    /**
     * The price that applied to a slot on a date — for later phases (orders, purchases).
     * @return array{id: int, amount: string, currency: string}|null
     */
    public function priceOn(int $productId, ?int $variantId, string $type, string $date, ?int $supplierId = null, ?int $gstId = null, ?string $channel = null, string $currency = 'INR'): ?array
    {
        $r = $this->db->first("SELECT id, amount, currency FROM product_prices WHERE slot_key = ? AND status IN ('active', 'superseded') AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)
            ORDER BY effective_from DESC, id DESC LIMIT 1", [self::slotKey($productId, $variantId, $type, $supplierId, $gstId, $channel, $currency), $date, $date]);
        return $r === null ? null : ['id' => (int) $r['id'], 'amount' => Money::toDb(Money::fromDb($r['amount'])), 'currency' => (string) $r['currency']];
    }

    public static function slotKey(int $productId, ?int $variantId, string $type, ?int $supplierId, ?int $gstId, ?string $channel, string $currency): string
    {
        return implode('|', [$productId, $variantId ?? 0, $type, $supplierId ?? 0, $gstId ?? 0, mb_strtolower((string) $channel), strtoupper($currency)]);
    }

    /**
     * Add a new price version. It applies at once, or waits for approval when the
     * price-change workflow is active.
     * @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array<string, mixed> the price row
     */
    public function add(array $p, array $data, array $actor): array
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        $type = (string) ($data['price_type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw ValidationException::withMessage('price_type', 'Choose a price type.');
        }
        $amountRaw = str_replace(',', '', trim((string) ($data['amount'] ?? '')));
        if (!Money::isValidInput($amountRaw)) {
            throw ValidationException::withMessage('amount', 'Enter an amount greater than zero with up to two decimals, e.g. 1499.00.');
        }
        $amount = Money::parse($amountRaw);
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'INR'))) ?: 'INR';
        if (!isset(\App\Services\Bank\BankAccountService::CURRENCIES[$currency])) {
            throw ValidationException::withMessage('currency', 'Choose a supported currency.');
        }
        $variantId = (int) ($data['variant_id'] ?? 0) ?: null;
        if ($variantId !== null && !$this->db->scalar("SELECT 1 FROM product_variants WHERE id = ? AND product_id = ? AND status <> 'archived'", [$variantId, $p['id']])) {
            throw ValidationException::withMessage('variant_id', 'Choose a variant of this product.');
        }
        $supplierId = (int) ($data['supplier_id'] ?? 0) ?: null;
        if ($type === 'supplier_purchase') {
            if ($supplierId === null) {
                throw ValidationException::withMessage('supplier_id', 'Choose the supplier this purchase price is from.');
            }
            $mapped = $this->db->scalar("SELECT 1 FROM product_supplier_mappings WHERE product_id = ? AND supplier_id = ? AND status = 'active' AND (variant_id IS NULL OR variant_id = ?)",
                [$p['id'], $supplierId, $variantId ?? 0]);
            if (!$mapped) {
                throw ValidationException::withMessage('supplier_id', 'Link this supplier to the product (Suppliers tab) before recording its purchase price.');
            }
        } else {
            $supplierId = null;
        }
        $gstId = (int) ($data['gst_registration_id'] ?? 0) ?: null;
        if ($gstId !== null && !(new DatedMappingService($this->db, 'product_gst'))->isActive((int) $p['id'], $gstId)) {
            throw ValidationException::withMessage('gst_registration_id', 'This product is not mapped to that GST registration. Map it first (GST Registrations tab).');
        }
        $channel = trim((string) ($data['sales_channel'] ?? ''));
        if ($type === 'marketplace_selling' && $channel === '') {
            throw ValidationException::withMessage('sales_channel', 'Enter the sales channel (e.g. AMAZON, FLIPKART, WEBSITE).');
        }
        if ($channel !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9 _.-]{1,59}$/', $channel) !== 1) {
            throw ValidationException::withMessage('sales_channel', 'Use 2–60 letters, digits, spaces, dots, hyphens or underscores.');
        }
        $channel = $channel !== '' ? strtoupper($channel) : null;
        $from = trim((string) ($data['effective_from'] ?? '')) ?: BusinessDate::today();
        $until = trim((string) ($data['effective_until'] ?? '')) ?: null;
        if (!BusinessDate::isValid($from) || ($until !== null && (!BusinessDate::isValid($until) || $until < $from))) {
            throw ValidationException::withMessage('effective_from', 'Enter a valid start date; an end date must be on or after it.');
        }
        $slot = self::slotKey((int) $p['id'], $variantId, $type, $supplierId, $gstId, $channel, $currency);
        $this->assertFits($slot, $from);
        $this->assertBounds((int) $p['id'], $variantId, $type, $amount, $currency, $from);
        $notes = trim((string) ($data['notes'] ?? ''));
        $id = $this->db->insert('product_prices', [
            'product_id' => (int) $p['id'], 'variant_id' => $variantId, 'price_type' => $type, 'amount' => Money::toDb($amount), 'currency' => $currency, 'supplier_id' => $supplierId,
            'gst_registration_id' => $gstId, 'sales_channel' => $channel, 'effective_from' => $from, 'effective_until' => $until, 'status' => 'pending_approval', 'slot_key' => $slot,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null, 'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $price = $this->find($id);
        $values = $this->view($price);
        App::activity()->log('product.price_requested', 'Requested ' . strtolower(self::TYPES[$type]) . ' ' . Money::format($amount) . " for {$p['product_code']}", 'product', (int) $p['id'],
            module: 'products', newValues: $values + ['price_id' => $id]);
        try {
            App::approvals()->submit('product_price_change', self::SUBJECT, $id, Money::toFloat($amount), self::TYPES[$type] . ": {$p['product_code']} — {$p['name']}", $actor, 'products.manage_pricing');
        } catch (\Throwable $e) {
            $this->db->update('product_prices', ['status' => 'cancelled', 'updated_at' => now()], ['id' => $id]);
            throw $e;
        }
        return $this->find($id) ?? [];
    }

    /**
     * Cancel a price that is waiting for approval, or an approved one that has not started
     * yet. Prices already in force are replaced by a new version, never cancelled or edited.
     * @param array<string, mixed> $price @param array<string, mixed> $actor
     */
    public function cancel(array $price, string $reason, array $actor): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($price['status'] === 'pending_approval') {
            $req = $this->db->first("SELECT id FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND status = 'pending'", [self::SUBJECT, $price['id']]);
            if ($req !== null) {
                App::approvals()->cancel((int) $req['id'], $actor); // the listener marks the price cancelled
            }
            $this->db->update('product_prices', ['status' => 'cancelled', 'decided_by' => (int) $actor['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $price['id'], 'status' => 'pending_approval']);
        } elseif ($price['status'] === 'active' && $price['effective_from'] > BusinessDate::today()) {
            $this->db->transaction(function () use ($price, $actor): void {
                $this->db->update('product_prices', ['status' => 'cancelled', 'decided_by' => (int) $actor['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $price['id']]);
                if ($price['supersedes_id'] !== null) {
                    // Re-open the version this one would have replaced.
                    $prev = $this->db->first('SELECT * FROM product_prices WHERE id = ?', [$price['supersedes_id']]);
                    if ($prev !== null && $prev['status'] === 'superseded' && $prev['effective_until'] === BusinessDate::addDays((string) $price['effective_from'], -1)) {
                        $this->db->update('product_prices', ['status' => 'active', 'effective_until' => null, 'updated_at' => now()], ['id' => $prev['id']]);
                    }
                }
            });
        } else {
            throw new DomainRuleException('Only prices waiting for approval or scheduled for a future date can be cancelled. To change a price in force, add a new price.');
        }
        App::activity()->log('product.price_cancelled', 'Cancelled ' . strtolower(self::TYPES[$price['price_type']]) . ' ' . Money::format(Money::fromDb($price['amount'])) . " of {$price['product_code']}", 'product',
            (int) $price['product_id'], module: 'products', oldValues: $this->view($price), newValues: ['status' => 'cancelled', 'reason' => $reason]);
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $price['product_id'], 'price_cancelled', $price['status'], 'cancelled', $this->view($price), null, $reason, null, $actor,
            $price['variant_id'] !== null ? (int) $price['variant_id'] : null);
    }

    /** Approval engine callback. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $price = $this->find((int) $request['subject_id']);
        if ($price === null || $price['status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = ['id' => $last !== null ? (int) $last['acted_by'] : (int) $price['created_by']];
        if ($status === 'approved') {
            $this->activate($price, $by);
            return;
        }
        $to = $status === 'rejected' ? 'rejected' : 'cancelled';
        $this->db->update('product_prices', ['status' => $to, 'decided_by' => $by['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $price['id']]);
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $price['product_id'], 'price_' . $to, 'pending_approval', $to, null, $this->view($price),
            $last['comment'] ?? null, null, $by, $price['variant_id'] !== null ? (int) $price['variant_id'] : null);
    }

    /** @param array<string, mixed> $price @param array<string, mixed> $by */
    private function activate(array $price, array $by): void
    {
        $this->db->transaction(function () use ($price, $by): void {
            $this->assertFits((string) $price['slot_key'], (string) $price['effective_from'], (int) $price['id']);
            $prev = $this->db->first("SELECT * FROM product_prices WHERE slot_key = ? AND status IN ('active', 'superseded') AND effective_from < ? AND (effective_until IS NULL OR effective_until >= ?)
                AND id <> ? ORDER BY effective_from DESC LIMIT 1", [$price['slot_key'], $price['effective_from'], $price['effective_from'], $price['id']]);
            $old = null;
            if ($prev !== null) {
                $this->db->update('product_prices', ['status' => 'superseded', 'effective_until' => BusinessDate::addDays((string) $price['effective_from'], -1), 'updated_at' => now()], ['id' => $prev['id']]);
                $old = ['amount' => Money::toDb(Money::fromDb($prev['amount'])), 'from' => $prev['effective_from'], 'price_id' => (int) $prev['id']];
            }
            $this->db->update('product_prices', ['status' => 'active', 'supersedes_id' => $prev['id'] ?? null, 'decided_by' => $by['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $price['id']]);
            HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $price['product_id'], 'price_set', null, 'active', $old, $this->view($price) + ['price_id' => (int) $price['id']],
                $price['notes'], (string) $price['effective_from'], $by, $price['variant_id'] !== null ? (int) $price['variant_id'] : null);
        });
        App::activity()->log('product.price_changed', self::TYPES[$price['price_type']] . ' of ' . $price['product_code'] . ' set to ' . Money::format(Money::fromDb($price['amount'])) . ' from ' . BusinessDate::format((string) $price['effective_from']),
            'product', (int) $price['product_id'], module: 'products', newValues: $this->view($price) + ['price_id' => (int) $price['id']], userId: $by['id']);
    }

    /** A new version must start after the version in force and before any later version. */
    private function assertFits(string $slot, string $from, int $exceptId = 0): void
    {
        $later = $this->db->first("SELECT effective_from, status FROM product_prices WHERE slot_key = ? AND status IN ('active', 'superseded', 'pending_approval') AND effective_from >= ? AND id <> ? ORDER BY effective_from LIMIT 1",
            [$slot, $from, $exceptId]);
        if ($later !== null) {
            $msg = $later['status'] === 'pending_approval'
                ? 'Another price for exactly this product, variant, type and scope is already waiting for approval. Decide or cancel it first.'
                : 'A price for exactly this scope already starts on ' . BusinessDate::format((string) $later['effective_from']) . '. A new price must start after it (cancel a scheduled price first if it is wrong).';
            throw ValidationException::withMessage('effective_from', $msg);
        }
    }

    /** Selling prices stay between the minimum and maximum selling prices in force on that date. */
    private function assertBounds(int $productId, ?int $variantId, string $type, int $amount, string $currency, string $date): void
    {
        $get = function (string $t) use ($productId, $variantId, $currency, $date): ?int {
            foreach ([$variantId, null] as $v) {
                $r = $this->priceOn($productId, $v, $t, $date, null, null, null, $currency);
                if ($r !== null) {
                    return Money::fromDb($r['amount']);
                }
                if ($variantId === null) {
                    break;
                }
            }
            return null;
        };
        $min = $get('minimum_selling');
        $max = $get('maximum_selling');
        if ($type === 'minimum_selling' && $max !== null && $amount > $max) {
            throw ValidationException::withMessage('amount', 'The minimum selling price cannot be above the maximum selling price (' . Money::format($max) . ').');
        }
        if ($type === 'maximum_selling' && $min !== null && $amount < $min) {
            throw ValidationException::withMessage('amount', 'The maximum selling price cannot be below the minimum selling price (' . Money::format($min) . ').');
        }
        if (in_array($type, self::BOUNDED, true)) {
            if ($min !== null && $amount < $min) {
                throw ValidationException::withMessage('amount', 'This is below the minimum selling price (' . Money::format($min) . ') in force on that date.');
            }
            if ($max !== null && $amount > $max) {
                throw ValidationException::withMessage('amount', 'This is above the maximum selling price (' . Money::format($max) . ') in force on that date.');
            }
        }
    }

    /** @param array<string, mixed> $price @return array<string, mixed> */
    private function view(array $price): array
    {
        return ['type' => $price['price_type'], 'amount' => Money::toDb(Money::fromDb($price['amount'])), 'currency' => $price['currency'], 'variant_id' => $price['variant_id'],
            'supplier_id' => $price['supplier_id'], 'gst_registration_id' => $price['gst_registration_id'], 'channel' => $price['sales_channel'],
            'from' => $price['effective_from'], 'until' => $price['effective_until']];
    }
}
