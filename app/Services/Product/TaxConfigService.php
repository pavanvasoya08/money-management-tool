<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Core\Validator;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\BusinessDate;

/**
 * GST / HSN configuration of products, built on the Phase 4 GST registrations.
 *
 * Masters (Super Admin): GST rates, tax categories and an HSN/SAC reference list.
 * Product configurations are versioned like prices: one row per product / variant /
 * GST registration and period; a new version closes the previous one. They wait for
 * approval when the GST/HSN change workflow is active. HSN/SAC codes are checked for
 * format only — the system makes no claim of official verification. No tax is calculated
 * and no return is filed here.
 */
final class TaxConfigService
{
    public const SUBJECT = 'product_tax_config';
    public const APPLICABILITY = ['taxable' => 'Taxable', 'exempt' => 'Exempt', 'nil_rated' => 'Nil rated', 'zero_rated' => 'Zero rated (exports / SEZ)', 'non_gst' => 'Non-GST supply'];
    public const INCLUSION = ['exclusive' => 'Prices exclude GST', 'inclusive' => 'Prices include GST'];
    public const STATUSES = PricingService::STATUSES;
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

    /** HSN: 4, 6 or 8 digits. SAC: 6 digits starting with 99. @return string|null error */
    public static function codeError(string $code, string $kind): ?string
    {
        if ($kind === 'sac') {
            return preg_match('/^99\d{4}$/', $code) === 1 ? null : 'A SAC code has 6 digits and starts with 99 (e.g. 998314).';
        }
        return preg_match('/^(\d{4}|\d{6}|\d{8})$/', $code) === 1 ? null : 'An HSN code has 4, 6 or 8 digits (e.g. 8517 or 85171300).';
    }

    // ---------------------------------------------------------------- masters

    /** @return list<array<string, mixed>> */
    public function rates(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT r.*, (SELECT COUNT(*) FROM product_tax_configs c WHERE c.gst_rate_id = r.id) AS used FROM gst_tax_rates r'
            . ($activeOnly ? ' WHERE r.is_active = 1' : '') . ' ORDER BY r.is_active DESC, r.sort_order, r.rate');
    }

    /** @return list<array<string, mixed>> */
    public function categories(bool $activeOnly = false): array
    {
        return $this->db->select('SELECT c.*, (SELECT COUNT(*) FROM product_tax_configs t WHERE t.tax_category_id = c.id) AS used FROM product_tax_categories c'
            . ($activeOnly ? ' WHERE c.is_active = 1' : '') . ' ORDER BY c.sort_order, c.name');
    }

    /** @return list<array<string, mixed>> */
    public function codes(string $q = '', bool $activeOnly = false): array
    {
        $sql = 'SELECT h.*, r.name AS rate_name, (SELECT COUNT(DISTINCT t.product_id) FROM product_tax_configs t WHERE t.hsn_sac_id = h.id) AS used FROM gst_hsn_sac_codes h LEFT JOIN gst_tax_rates r ON r.id = h.default_rate_id WHERE 1 = 1';
        $p = [];
        if ($activeOnly) {
            $sql .= ' AND h.is_active = 1';
        }
        if (trim($q) !== '') {
            $sql .= ' AND (h.code LIKE ? OR LOWER(h.description) LIKE ?)';
            array_push($p, trim($q) . '%', '%' . mb_strtolower(trim($q)) . '%');
        }
        return $this->db->select($sql . ' ORDER BY h.code LIMIT 500', $p);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveRate(array $data, array $actor, ?int $id = null): int
    {
        $existing = $id !== null ? ($this->db->first('SELECT * FROM gst_tax_rates WHERE id = ?', [$id]) ?? throw new DomainRuleException('Rate not found.')) : null;
        $data['code'] = strtoupper(trim((string) ($data['code'] ?? '')));
        $v = Validator::make($data, ['code' => 'required|regex:/^[A-Z0-9_]{2,20}$/', 'name' => 'required|string|max:60', 'rate' => 'required|regex:/^\d{1,3}(\.\d{1,2})?$/',
            'cess_rate' => 'nullable|regex:/^\d{1,3}(\.\d{1,2})?$/', 'description' => 'nullable|string|max:255'], ['cess_rate' => 'Cess rate']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if ((float) $data['rate'] > 100 || (float) ($data['cess_rate'] ?? 0) > 400) {
            throw ValidationException::withMessage('rate', 'Enter a rate between 0 and 100 (cess up to 400).');
        }
        if ($this->db->scalar('SELECT 1 FROM gst_tax_rates WHERE code = ?' . ($id ? ' AND id <> ?' : ''), array_merge([$data['code']], $id ? [$id] : []))) {
            throw ValidationException::withMessage('code', 'This code is already used.');
        }
        $rate = number_format((float) $data['rate'], 2, '.', '');
        $used = $existing !== null ? (int) $this->db->scalar('SELECT COUNT(*) FROM product_tax_configs WHERE gst_rate_id = ?', [$id]) : 0;
        if ($used > 0 && $rate !== number_format((float) $existing['rate'], 2, '.', '')) {
            throw ValidationException::withMessage('rate', 'Products already use this rate, so its percentage cannot change (their history would change too). Retire it and add a new rate.');
        }
        $row = ['code' => $data['code'], 'name' => trim((string) $data['name']), 'rate' => $rate, 'cess_rate' => number_format((float) ($data['cess_rate'] ?? 0), 2, '.', ''),
            'description' => trim((string) ($data['description'] ?? '')) ?: null, 'is_active' => !empty($data['is_active']) || $existing === null ? 1 : 0,
            'sort_order' => max(0, min(9999, (int) ($data['sort_order'] ?? 0))), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        return $this->persist('gst_tax_rates', 'gst_rate', $row, $existing, $actor);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveCategory(array $data, array $actor, ?int $id = null): int
    {
        $existing = $id !== null ? ($this->db->first('SELECT * FROM product_tax_categories WHERE id = ?', [$id]) ?? throw new DomainRuleException('Tax category not found.')) : null;
        $data['code'] = strtolower(trim((string) ($data['code'] ?? '')));
        $v = Validator::make($data, ['code' => 'required|regex:/^[a-z0-9_]{2,40}$/', 'name' => 'required|string|max:100', 'description' => 'nullable|string|max:255']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        if ($this->db->scalar('SELECT 1 FROM product_tax_categories WHERE code = ?' . ($id ? ' AND id <> ?' : ''), array_merge([$data['code']], $id ? [$id] : []))) {
            throw ValidationException::withMessage('code', 'This code is already used.');
        }
        $row = ['code' => $data['code'], 'name' => trim((string) $data['name']), 'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'is_active' => !empty($data['is_active']) || $existing === null ? 1 : 0, 'sort_order' => max(0, min(9999, (int) ($data['sort_order'] ?? 0))), 'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        return $this->persist('product_tax_categories', 'tax_category', $row, $existing, $actor);
    }

    /** @param array<string, mixed> $data @param array<string, mixed> $actor */
    public function saveCode(array $data, array $actor, ?int $id = null): int
    {
        $existing = $id !== null ? ($this->db->first('SELECT * FROM gst_hsn_sac_codes WHERE id = ?', [$id]) ?? throw new DomainRuleException('Code not found.')) : null;
        $code = preg_replace('/\s+/', '', (string) ($data['code'] ?? '')) ?? '';
        $kind = ($data['kind'] ?? '') === 'sac' ? 'sac' : 'hsn';
        if (($err = self::codeError($code, $kind)) !== null) {
            throw ValidationException::withMessage('code', $err);
        }
        if ($existing !== null && $existing['code'] !== $code && $this->db->scalar('SELECT 1 FROM product_tax_configs WHERE hsn_sac_id = ?', [$id])) {
            throw ValidationException::withMessage('code', 'Products already use this code, so the code itself cannot change.');
        }
        if ($this->db->scalar('SELECT 1 FROM gst_hsn_sac_codes WHERE code = ?' . ($id ? ' AND id <> ?' : ''), array_merge([$code], $id ? [$id] : []))) {
            throw ValidationException::withMessage('code', 'This code is already in the list.');
        }
        $desc = trim((string) ($data['description'] ?? ''));
        if ($desc === '' || mb_strlen($desc) > 500) {
            throw ValidationException::withMessage('description', 'Enter a description of up to 500 characters.');
        }
        $rate = (int) ($data['default_rate_id'] ?? 0) ?: null;
        if ($rate !== null && !$this->db->scalar('SELECT 1 FROM gst_tax_rates WHERE id = ?', [$rate])) {
            $rate = null;
        }
        $row = ['code' => $code, 'kind' => $kind, 'description' => $desc, 'default_rate_id' => $rate, 'is_active' => !empty($data['is_active']) || $existing === null ? 1 : 0,
            'updated_by' => (int) $actor['id'], 'updated_at' => now()];
        return $this->persist('gst_hsn_sac_codes', 'hsn_sac_code', $row, $existing, $actor);
    }

    // ---------------------------------------------------------------- product configurations

    /** @return list<array<string, mixed>> all versions, newest first */
    public function forProduct(int $productId): array
    {
        return $this->db->select($this->select() . ' WHERE t.product_id = ? ORDER BY t.effective_from DESC, t.id DESC', [$productId]);
    }

    /** Configurations in force on a date (default today). @return list<array<string, mixed>> */
    public function current(int $productId, ?string $date = null): array
    {
        $date ??= BusinessDate::today();
        return $this->db->select($this->select() . " WHERE t.product_id = ? AND t.status IN ('active', 'superseded') AND t.effective_from <= ? AND (t.effective_until IS NULL OR t.effective_until >= ?)
            ORDER BY CASE WHEN t.variant_id IS NULL THEN 0 ELSE 1 END, CASE WHEN t.gst_registration_id IS NULL THEN 0 ELSE 1 END", [$productId, $date, $date]);
    }

    public function hasCurrent(int $productId): bool
    {
        $d = BusinessDate::today();
        return (bool) $this->db->scalar("SELECT 1 FROM product_tax_configs WHERE product_id = ? AND status IN ('active', 'superseded') AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)", [$productId, $d, $d]);
    }

    /**
     * The configuration that applies to a product / variant / GST registration on a date:
     * the most specific one wins (variant + registration, variant, product + registration, product).
     * For later phases (orders, purchases, GST reports). @return array<string, mixed>|null
     */
    public function resolve(int $productId, ?int $variantId, ?int $gstId, ?string $date = null): ?array
    {
        $date ??= BusinessDate::today();
        $order = [];
        if ($variantId !== null && $gstId !== null) {
            $order[] = [$variantId, $gstId];
        }
        if ($variantId !== null) {
            $order[] = [$variantId, null];
        }
        if ($gstId !== null) {
            $order[] = [null, $gstId];
        }
        $order[] = [null, null];
        foreach ($order as [$v, $g]) {
            $r = $this->db->first($this->select() . " WHERE t.slot_key = ? AND t.status IN ('active', 'superseded') AND t.effective_from <= ? AND (t.effective_until IS NULL OR t.effective_until >= ?) ORDER BY t.effective_from DESC LIMIT 1",
                [self::slotKey($productId, $v, $g), $date, $date]);
            if ($r !== null) {
                return $r;
            }
        }
        return null;
    }

    public static function slotKey(int $productId, ?int $variantId, ?int $gstId): string
    {
        return implode('|', [$productId, $variantId ?? 0, $gstId ?? 0]);
    }

    /**
     * Add a configuration version. Changing the HSN/SAC needs products.manage_hsn; changing
     * the rate, tax category, applicability or price inclusion needs products.manage_gst.
     * @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor @return array<string, mixed>
     */
    public function add(array $p, array $data, array $actor): array
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only.');
        }
        $variantId = (int) ($data['variant_id'] ?? 0) ?: null;
        if ($variantId !== null && !$this->db->scalar("SELECT 1 FROM product_variants WHERE id = ? AND product_id = ? AND status <> 'archived'", [$variantId, $p['id']])) {
            throw ValidationException::withMessage('variant_id', 'Choose a variant of this product.');
        }
        $gstId = (int) ($data['gst_registration_id'] ?? 0) ?: null;
        if ($gstId !== null && !(new DatedMappingService($this->db, 'product_gst'))->isActive((int) $p['id'], $gstId)) {
            throw ValidationException::withMessage('gst_registration_id', 'This product is not mapped to that GST registration. Map it first (GST Registrations tab).');
        }
        $applicability = (string) ($data['applicability'] ?? 'taxable');
        if (!isset(self::APPLICABILITY[$applicability])) {
            throw ValidationException::withMessage('applicability', 'Choose the tax applicability.');
        }
        $category = (int) ($data['tax_category_id'] ?? 0);
        if (!$this->db->scalar('SELECT 1 FROM product_tax_categories WHERE id = ? AND is_active = 1', [$category])) {
            throw ValidationException::withMessage('tax_category_id', 'Choose an active tax category.');
        }
        $from = trim((string) ($data['effective_from'] ?? '')) ?: BusinessDate::today();
        $until = trim((string) ($data['effective_until'] ?? '')) ?: null;
        if (!BusinessDate::isValid($from) || ($until !== null && (!BusinessDate::isValid($until) || $until < $from))) {
            throw ValidationException::withMessage('effective_from', 'Enter a valid start date; an end date must be on or after it.');
        }
        $rateId = (int) ($data['gst_rate_id'] ?? 0) ?: null;
        if ($applicability === 'taxable') {
            $rate = $rateId !== null ? $this->db->first('SELECT * FROM gst_tax_rates WHERE id = ?', [$rateId]) : null;
            if ($rate === null) {
                throw ValidationException::withMessage('gst_rate_id', 'Choose the GST rate for a taxable product.');
            }
            if (!(int) $rate['is_active'] && ($until === null || $until >= BusinessDate::today())) {
                throw ValidationException::withMessage('gst_rate_id', "{$rate['name']} is retired. It can only be used to record a past period (give an end date before today).");
            }
        } else {
            $rateId = null;
        }
        $isService = (int) $this->db->scalar('SELECT is_service FROM product_types WHERE id = ?', [$p['product_type_id']]) === 1;
        $hsn = preg_replace('/\s+/', '', (string) ($data['hsn_code'] ?? '')) ?: null;
        $sac = preg_replace('/\s+/', '', (string) ($data['sac_code'] ?? '')) ?: null;
        if ($hsn !== null && ($err = self::codeError($hsn, 'hsn')) !== null) {
            throw ValidationException::withMessage('hsn_code', $err);
        }
        if ($sac !== null && ($err = self::codeError($sac, 'sac')) !== null) {
            throw ValidationException::withMessage('sac_code', $err);
        }
        if ($applicability !== 'non_gst') {
            if ($isService && $sac === null) {
                throw ValidationException::withMessage('sac_code', 'Services need a SAC code.');
            }
            if (!$isService && $hsn === null) {
                throw ValidationException::withMessage('hsn_code', 'Goods need an HSN code.');
            }
        }
        $code = $hsn ?? $sac;
        $master = $code !== null ? $this->db->first('SELECT id FROM gst_hsn_sac_codes WHERE code = ?', [$code]) : null;
        $inclusion = ($data['price_inclusion'] ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
        $slot = self::slotKey((int) $p['id'], $variantId, $gstId);

        // Who may change what: compare with the configuration in force on the start date.
        $prev = $this->db->first("SELECT * FROM product_tax_configs WHERE slot_key = ? AND status IN ('active', 'superseded') AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?) ORDER BY effective_from DESC LIMIT 1",
            [$slot, $from, $from]);
        $codeChanged = $prev === null || (string) $prev['hsn_code'] !== (string) $hsn || (string) $prev['sac_code'] !== (string) $sac;
        $taxChanged = $prev === null || (int) $prev['gst_rate_id'] !== (int) $rateId || (int) $prev['tax_category_id'] !== $category || $prev['applicability'] !== $applicability || $prev['price_inclusion'] !== $inclusion;
        if ($codeChanged && !App::gate()->allows($actor, 'products.manage_hsn')) {
            throw new AuthorizationException('products.manage_hsn', 'You may not set or change HSN / SAC codes.');
        }
        if ($taxChanged && !App::gate()->allows($actor, 'products.manage_gst')) {
            throw new AuthorizationException('products.manage_gst', 'You may not set or change GST rates, tax categories or applicability.');
        }
        if (!$codeChanged && !$taxChanged && ($prev['effective_until'] ?? null) === $until) {
            throw ValidationException::withMessage('effective_from', 'Nothing changes compared with the configuration in force on that date.');
        }
        $this->assertFits($slot, $from);
        $notes = trim((string) ($data['notes'] ?? ''));
        $id = $this->db->insert('product_tax_configs', [
            'product_id' => (int) $p['id'], 'variant_id' => $variantId, 'gst_registration_id' => $gstId, 'hsn_code' => $hsn, 'sac_code' => $sac, 'hsn_sac_id' => $master['id'] ?? null,
            'tax_category_id' => $category, 'gst_rate_id' => $rateId, 'applicability' => $applicability, 'price_inclusion' => $inclusion, 'effective_from' => $from, 'effective_until' => $until,
            'status' => 'pending_approval', 'slot_key' => $slot, 'notes' => $notes !== '' ? mb_substr($notes, 0, 255) : null,
            'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cfg = $this->find($id);
        App::activity()->log('product.tax_requested', "Requested GST/HSN configuration for {$p['product_code']} (" . ($code ?? 'no code') . ', ' . ($cfg['rate_name'] ?? self::APPLICABILITY[$applicability]) . ')', 'product', (int) $p['id'],
            module: 'products', oldValues: $prev !== null ? $this->view($prev) : null, newValues: $this->view($cfg) + ['config_id' => $id]);
        try {
            App::approvals()->submit('product_tax_change', self::SUBJECT, $id, 0.0, "GST/HSN change: {$p['product_code']} — {$p['name']}", $actor, $taxChanged ? 'products.manage_gst' : 'products.manage_hsn');
        } catch (\Throwable $e) {
            $this->db->update('product_tax_configs', ['status' => 'cancelled', 'updated_at' => now()], ['id' => $id]);
            throw $e;
        }
        return $this->find($id) ?? [];
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first($this->select() . ' WHERE t.id = ?', [$id]);
    }

    /** Cancel a pending or not-yet-started configuration. @param array<string, mixed> $cfg @param array<string, mixed> $actor */
    public function cancel(array $cfg, string $reason, array $actor): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($cfg['status'] === 'pending_approval') {
            $req = $this->db->first("SELECT id FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND status = 'pending'", [self::SUBJECT, $cfg['id']]);
            if ($req !== null) {
                App::approvals()->cancel((int) $req['id'], $actor);
            }
            $this->db->update('product_tax_configs', ['status' => 'cancelled', 'decided_by' => (int) $actor['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $cfg['id'], 'status' => 'pending_approval']);
        } elseif ($cfg['status'] === 'active' && $cfg['effective_from'] > BusinessDate::today()) {
            $this->db->transaction(function () use ($cfg, $actor): void {
                $this->db->update('product_tax_configs', ['status' => 'cancelled', 'decided_by' => (int) $actor['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $cfg['id']]);
                if ($cfg['supersedes_id'] !== null) {
                    $prev = $this->db->first('SELECT * FROM product_tax_configs WHERE id = ?', [$cfg['supersedes_id']]);
                    if ($prev !== null && $prev['status'] === 'superseded' && $prev['effective_until'] === BusinessDate::addDays((string) $cfg['effective_from'], -1)) {
                        $this->db->update('product_tax_configs', ['status' => 'active', 'effective_until' => null, 'updated_at' => now()], ['id' => $prev['id']]);
                    }
                }
            });
        } else {
            throw new DomainRuleException('Only configurations waiting for approval or starting in the future can be cancelled. To change one in force, add a new configuration.');
        }
        HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $cfg['product_id'], 'tax_cancelled', $cfg['status'], 'cancelled', $this->view($cfg), null, $reason, null, $actor,
            $cfg['variant_id'] !== null ? (int) $cfg['variant_id'] : null);
        App::activity()->log('product.tax_cancelled', 'Cancelled a GST/HSN configuration of product #' . $cfg['product_id'], 'product', (int) $cfg['product_id'], module: 'products',
            oldValues: $this->view($cfg), newValues: ['status' => 'cancelled', 'reason' => $reason]);
    }

    /** Approval engine callback. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $cfg = $this->find((int) $request['subject_id']);
        if ($cfg === null || $cfg['status'] !== 'pending_approval') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = ['id' => $last !== null ? (int) $last['acted_by'] : (int) $cfg['created_by']];
        $variant = $cfg['variant_id'] !== null ? (int) $cfg['variant_id'] : null;
        if ($status !== 'approved') {
            $to = $status === 'rejected' ? 'rejected' : 'cancelled';
            $this->db->update('product_tax_configs', ['status' => $to, 'decided_by' => $by['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $cfg['id']]);
            HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $cfg['product_id'], 'tax_' . $to, 'pending_approval', $to, null, $this->view($cfg), $last['comment'] ?? null, null, $by, $variant);
            return;
        }
        $this->db->transaction(function () use ($cfg, $by, $variant): void {
            $this->assertFits((string) $cfg['slot_key'], (string) $cfg['effective_from'], (int) $cfg['id']);
            $prev = $this->db->first("SELECT * FROM product_tax_configs WHERE slot_key = ? AND status IN ('active', 'superseded') AND effective_from < ? AND (effective_until IS NULL OR effective_until >= ?) AND id <> ?
                ORDER BY effective_from DESC LIMIT 1", [$cfg['slot_key'], $cfg['effective_from'], $cfg['effective_from'], $cfg['id']]);
            if ($prev !== null) {
                $this->db->update('product_tax_configs', ['status' => 'superseded', 'effective_until' => BusinessDate::addDays((string) $cfg['effective_from'], -1), 'updated_at' => now()], ['id' => $prev['id']]);
            }
            $this->db->update('product_tax_configs', ['status' => 'active', 'supersedes_id' => $prev['id'] ?? null, 'decided_by' => $by['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $cfg['id']]);
            HistoryWriter::write($this->db, 'product_history', 'product_id', (int) $cfg['product_id'], 'tax_set', null, 'active', $prev !== null ? $this->view($prev) : null, $this->view($cfg),
                $cfg['notes'], (string) $cfg['effective_from'], $by, $variant);
        });
        App::activity()->log('product.tax_changed', 'GST/HSN configuration of product #' . $cfg['product_id'] . ' in force from ' . BusinessDate::format((string) $cfg['effective_from']), 'product', (int) $cfg['product_id'],
            module: 'products', newValues: $this->view($cfg) + ['config_id' => (int) $cfg['id']], userId: $by['id']);
    }

    // ---------------------------------------------------------------- internals

    private function select(): string
    {
        return 'SELECT t.*, r.name AS rate_name, r.rate AS rate_percent, r.cess_rate, c.name AS category_name, v.name AS variant_name, g.gstin, h.description AS code_description,
            cu.name AS created_by_name, du.name AS decided_by_name
            FROM product_tax_configs t LEFT JOIN gst_tax_rates r ON r.id = t.gst_rate_id JOIN product_tax_categories c ON c.id = t.tax_category_id
            LEFT JOIN product_variants v ON v.id = t.variant_id LEFT JOIN gst_registrations g ON g.id = t.gst_registration_id LEFT JOIN gst_hsn_sac_codes h ON h.id = t.hsn_sac_id
            LEFT JOIN users cu ON cu.id = t.created_by LEFT JOIN users du ON du.id = t.decided_by';
    }

    private function assertFits(string $slot, string $from, int $exceptId = 0): void
    {
        $later = $this->db->first("SELECT effective_from, status FROM product_tax_configs WHERE slot_key = ? AND status IN ('active', 'superseded', 'pending_approval') AND effective_from >= ? AND id <> ? ORDER BY effective_from LIMIT 1",
            [$slot, $from, $exceptId]);
        if ($later !== null) {
            throw ValidationException::withMessage('effective_from', $later['status'] === 'pending_approval'
                ? 'Another configuration for this scope is already waiting for approval. Decide or cancel it first.'
                : 'A configuration for this scope already starts on ' . BusinessDate::format((string) $later['effective_from']) . '. A new one must start after it.');
        }
    }

    /** @param array<string, mixed> $c @return array<string, mixed> */
    private function view(array $c): array
    {
        return ['hsn' => $c['hsn_code'], 'sac' => $c['sac_code'], 'rate' => $c['rate_name'] ?? $c['gst_rate_id'], 'category_id' => (int) $c['tax_category_id'], 'applicability' => $c['applicability'],
            'inclusion' => $c['price_inclusion'], 'variant_id' => $c['variant_id'], 'gst_registration_id' => $c['gst_registration_id'], 'from' => $c['effective_from'], 'until' => $c['effective_until']];
    }

    /** @param array<string, mixed> $row @param array<string, mixed>|null $existing @param array<string, mixed> $actor */
    private function persist(string $table, string $kind, array $row, ?array $existing, array $actor): int
    {
        if ($existing === null) {
            $id = $this->db->insert($table, $row + ['created_by' => (int) $actor['id'], 'created_at' => now()]);
            App::activity()->log("product.{$kind}_created", 'Added ' . str_replace('_', ' ', $kind) . " {$row['code']}", 'product_master', $id, module: 'products', newValues: array_diff_key($row, ['updated_by' => 1, 'updated_at' => 1]));
            return $id;
        }
        $old = array_intersect_key($existing, $row);
        $this->db->update($table, $row, ['id' => $existing['id']]);
        App::activity()->log("product.{$kind}_updated", 'Updated ' . str_replace('_', ' ', $kind) . " {$row['code']}", 'product_master', (int) $existing['id'], module: 'products',
            oldValues: array_diff_key($old, ['updated_by' => 1, 'updated_at' => 1]), newValues: array_diff_key($row, ['updated_by' => 1, 'updated_at' => 1]));
        return (int) $existing['id'];
    }
}
