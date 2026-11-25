<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Validator;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;
use App\Services\Approval\ApprovalEngine;
use App\Support\Barcode;
use App\Support\BusinessDate;
use App\Support\Uuid;

/**
 * Product master: draft → (approval) → active ↔ inactive / discontinued → archived.
 * Key-detail changes, SKU changes, archival and preferred-supplier changes go through the
 * approval engine (inactive workflows approve at once). Products are never deleted; archived
 * products stay readable so historical records keep showing their original details, and are
 * refused for new transactions unless an explicit exception is set.
 */
final class ProductService
{
    public const SUBJECT = 'product_change_request';
    public const STATUSES = ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'active' => 'Active', 'inactive' => 'Inactive', 'discontinued' => 'Discontinued', 'archived' => 'Archived'];
    /** target => [allowed from, permission action, label] */
    public const TRANSITIONS = [
        'active' => [['inactive', 'discontinued'], 'activate', 'Activate'],
        'inactive' => [['active'], 'deactivate', 'Deactivate'],
        'discontinued' => [['active', 'inactive'], 'deactivate', 'Discontinue'],
    ];
    /** Changes to these on a non-draft product go through the key-detail change workflow. */
    public const KEY_FIELDS = ['name', 'product_type_id', 'category_id', 'subcategory_id', 'brand_id', 'unit', 'barcode', 'barcode_type', 'tracks_inventory'];
    public const DIRECT_FIELDS = ['short_description', 'description', 'manufacturer', 'model_number', 'tags', 'internal_notes'];
    public const UNITS = ['pcs' => 'Pieces', 'set' => 'Set', 'pack' => 'Pack', 'box' => 'Box', 'pair' => 'Pair', 'dozen' => 'Dozen', 'kg' => 'Kilogram', 'g' => 'Gram',
        'l' => 'Litre', 'ml' => 'Millilitre', 'm' => 'Metre', 'cm' => 'Centimetre', 'sqm' => 'Square metre', 'hour' => 'Hour', 'service' => 'Service unit'];
    public const WORKFLOWS = ['create' => 'product_create', 'update' => 'product_update', 'sku' => 'product_sku_change', 'archive' => 'product_archive', 'preferred_supplier' => 'product_preferred_supplier'];
    private const TITLES = ['create' => 'New product', 'update' => 'Product key-detail change', 'sku' => 'SKU change', 'archive' => 'Product archival', 'preferred_supplier' => 'Preferred supplier change'];
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

    // ---------------------------------------------------------------- reading

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first(
            'SELECT p.*, t.name AS type_name, t.code AS type_code, t.is_service, t.has_components, c.name AS category_name, sc.name AS subcategory_name, b.name AS brand_name,
                cu.name AS created_by_name, uu.name AS updated_by_name
             FROM products p JOIN product_types t ON t.id = p.product_type_id LEFT JOIN product_categories c ON c.id = p.category_id
             LEFT JOIN product_categories sc ON sc.id = p.subcategory_id LEFT JOIN product_brands b ON b.id = p.brand_id
             LEFT JOIN users cu ON cu.id = p.created_by LEFT JOIN users uu ON uu.id = p.updated_by WHERE p.id = ?',
            [$id]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        $id = $this->db->scalar('SELECT id FROM products WHERE uuid = ?', [$uuid]);
        return $id === null ? null : $this->find((int) $id);
    }

    /**
     * @param array<string, mixed> $filters q, status, type, category, brand, gst, activity, supplier, hsn, archived, missing
     * @param array{0: string, 1: list<mixed>} $scope constraint on alias p
     * @return array{0: string, 1: list<mixed>}
     */
    public function filterSql(array $filters, array $scope): array
    {
        [$where, $params] = $scope;
        $sql = [$where];
        $status = (string) ($filters['status'] ?? '');
        if (isset(self::STATUSES[$status])) {
            $sql[] = 'p.status = ?';
            $params[] = $status;
        } elseif (($filters['archived'] ?? '') === 'only') {
            $sql[] = "p.status = 'archived'";
        } elseif (($filters['archived'] ?? '') !== 'with') {
            $sql[] = "p.status <> 'archived'";
        }
        if (($q = trim((string) ($filters['q'] ?? ''))) !== '') {
            $like = '%' . mb_strtolower($q) . '%';
            $sql[] = '(LOWER(p.name) LIKE ? OR LOWER(p.product_code) LIKE ? OR LOWER(p.sku) LIKE ? OR p.barcode = ? OR LOWER(p.tags) LIKE ? OR LOWER(p.model_number) LIKE ?
                OR p.id IN (SELECT v.product_id FROM product_variants v WHERE LOWER(v.sku) LIKE ? OR LOWER(v.name) LIKE ? OR v.barcode = ?))';
            array_push($params, $like, $like, $like, $q, $like, $like, $like, $like, $q);
        }
        if ((int) ($filters['category'] ?? 0) > 0) {
            $ids = array_merge([(int) $filters['category']], (new CatalogService($this->db))->descendantIds((int) $filters['category']));
            $in = implode(',', array_fill(0, count($ids), '?'));
            $sql[] = "(p.category_id IN ({$in}) OR p.subcategory_id IN ({$in}))";
            array_push($params, ...$ids, ...$ids);
        }
        foreach (['type' => 'p.product_type_id = ?', 'brand' => 'p.brand_id = ?',
            'gst' => "p.id IN (SELECT product_id FROM product_gst_mappings WHERE status = 'active' AND gst_registration_id = ?)",
            'activity' => "p.id IN (SELECT product_id FROM product_business_mappings WHERE status = 'active' AND business_activity_id = ?)",
            'supplier' => "p.id IN (SELECT product_id FROM product_supplier_mappings WHERE status = 'active' AND supplier_id = ?)"] as $k => $cond) {
            if ((int) ($filters[$k] ?? 0) > 0) {
                $sql[] = $cond;
                $params[] = (int) $filters[$k];
            }
        }
        $today = BusinessDate::today();
        $current = "status IN ('active', 'superseded') AND effective_from <= ? AND (effective_until IS NULL OR effective_until >= ?)";
        if (($hsn = preg_replace('/\D/', '', (string) ($filters['hsn'] ?? ''))) !== '') {
            $sql[] = "p.id IN (SELECT product_id FROM product_tax_configs WHERE {$current} AND (hsn_code LIKE ? OR sac_code LIKE ?))";
            array_push($params, $today, $today, $hsn . '%', $hsn . '%');
        }
        match ((string) ($filters['missing'] ?? '')) {
            'sku' => $sql[] = "(p.sku IS NULL OR p.sku = '')",
            'hsn' => (function () use (&$sql, &$params, $current, $today): void {
                $sql[] = "p.id NOT IN (SELECT product_id FROM product_tax_configs WHERE {$current} AND (hsn_code IS NOT NULL OR sac_code IS NOT NULL))";
                array_push($params, $today, $today);
            })(),
            'tax' => (function () use (&$sql, &$params, $current, $today): void {
                $sql[] = "p.id NOT IN (SELECT product_id FROM product_tax_configs WHERE {$current})";
                array_push($params, $today, $today);
            })(),
            'supplier' => $sql[] = "p.id NOT IN (SELECT product_id FROM product_supplier_mappings WHERE status = 'active')",
            default => null,
        };
        return [implode(' AND ', $sql), $params];
    }

    /** @param array<string, mixed> $filters @param array{0: string, 1: list<mixed>} $scope */
    public function paginate(array $filters, int $page, int $perPage, array $scope): Paginator
    {
        [$w, $p] = $this->filterSql($filters, $scope);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM products p WHERE {$w}", $p);
        $order = match ((string) ($filters['sort'] ?? '')) {
            'code' => 'p.product_code', 'sku' => 'p.sku', 'status' => 'p.status, p.name', 'updated' => 'p.updated_at DESC', 'created' => 'p.created_at DESC', default => 'p.name',
        };
        $rows = $this->db->select("SELECT p.*, t.name AS type_name, c.name AS category_name, sc.name AS subcategory_name, b.name AS brand_name,
                (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.status <> 'archived') AS variant_count,
                (SELECT COUNT(*) FROM product_supplier_mappings m WHERE m.product_id = p.id AND m.status = 'active') AS supplier_count,
                (SELECT i.id FROM product_images i WHERE i.product_id = p.id AND i.variant_id IS NULL AND i.status = 'active' ORDER BY i.is_primary DESC, i.sort_order, i.id LIMIT 1) AS image_id
            FROM products p JOIN product_types t ON t.id = p.product_type_id LEFT JOIN product_categories c ON c.id = p.category_id
            LEFT JOIN product_categories sc ON sc.id = p.subcategory_id LEFT JOIN product_brands b ON b.id = p.brand_id
            WHERE {$w} ORDER BY {$order} LIMIT " . max(1, $perPage) . ' OFFSET ' . max(0, ($page - 1) * $perPage), $p);
        return new Paginator($rows, $total, $page, $perPage);
    }

    /** @return list<array<string, mixed>> */
    public function changes(int $productId): array
    {
        $rows = $this->db->select('SELECT c.*, u.name AS requested_by_name, v.name AS variant_name FROM product_change_requests c LEFT JOIN users u ON u.id = c.requested_by
            LEFT JOIN product_variants v ON v.id = c.variant_id WHERE c.product_id = ? ORDER BY c.id DESC', [$productId]);
        foreach ($rows as &$r) {
            $r['old_values'] = json_decode((string) $r['old_values'], true);
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function change(int $id): ?array
    {
        $r = $this->db->first('SELECT c.*, p.product_code, p.name AS product_name FROM product_change_requests c JOIN products p ON p.id = c.product_id WHERE c.id = ?', [$id]);
        if ($r !== null) {
            $r['old_values'] = json_decode((string) $r['old_values'], true);
            $r['new_values'] = json_decode((string) $r['new_values'], true);
        }
        return $r;
    }

    /** @return list<array<string, mixed>> */
    public function pendingChanges(int $productId): array
    {
        return array_values(array_filter($this->changes($productId), static fn (array $c): bool => $c['status'] === 'pending'));
    }

    /** @return list<array<string, mixed>> */
    public function historyFor(int $productId): array
    {
        return HistoryWriter::read($this->db, 'product_history', 'product_id', $productId);
    }

    // ---------------------------------------------------------------- create / edit

    /**
     * Create a draft product (optionally submitting it at once). A SKU typed by the user is
     * used when manual SKUs are allowed; otherwise one is generated from the pattern when
     * automatic generation is on.
     * @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{0: int, 1: array<string, mixed>|null} [id, change request when submitted]
     */
    public function create(array $data, array $actor, bool $submit = false): array
    {
        $clean = $this->validate($data, null);
        $skus = new SkuService($this->db);
        $sku = SkuService::normalize((string) ($data['sku'] ?? ''));
        if ($sku !== '') {
            if (!setting('product_sku_manual_allowed', true)) {
                throw ValidationException::withMessage('sku', 'SKUs are generated automatically. Leave the SKU empty.');
            }
            $skus->assertAvailable($sku);
        }
        $id = $this->db->transaction(function (Database $db) use ($clean, $actor, $sku, $skus): int {
            $generated = false;
            if ($sku === '' && setting('product_sku_auto_generate', true)) {
                $sku = $skus->generate((string) setting('product_sku_pattern', '{CAT}-{SEQ:5}'), $skus->productTokens($clean));
                $generated = true;
            }
            $row = $clean + ['uuid' => Uuid::v4(), 'product_code' => $this->nextCode(), 'sku' => $sku !== '' ? $sku : null, 'status' => 'draft',
                'created_by' => (int) $actor['id'], 'updated_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now()];
            $id = $db->insert('products', $row);
            if ($sku !== '') {
                $skus->register($sku, $id, null, $generated, (int) $actor['id']);
            }
            $this->history($id, 'created', null, 'draft', null, $this->auditView($row), null, null, $actor);
            return $id;
        });
        $p = $this->find($id);
        App::activity()->log('product.created', "Added product {$p['product_code']} ({$p['name']})", 'product', $id, module: 'products', newValues: $this->auditView($p));
        $change = $submit ? $this->submit($p, $actor) : null;
        return [$id, $change];
    }

    /**
     * Update a product. Drafts: everything at once. Others: descriptive fields at once,
     * key fields through the key-detail change workflow.
     * @param array<string, mixed> $p @param array<string, mixed> $data @param array<string, mixed> $actor
     * @return array{direct: list<string>, key: list<string>, change: array<string, mixed>|null}
     */
    public function update(array $p, array $data, array $actor, ?string $reason = null): array
    {
        $this->assertWritable($p);
        if ($p['status'] === 'pending_approval') {
            throw new DomainRuleException('This product is waiting for approval. Decide or withdraw the request first.');
        }
        $clean = $this->validate($data, $p);
        $changed = [];
        $old = [];
        foreach ($clean as $k => $v) {
            if ((string) ($p[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[$k] = $v;
                $old[$k] = $p[$k];
            }
        }
        $key = [];
        $direct = $changed;
        if ($p['status'] !== 'draft') {
            foreach ($changed as $k => $v) {
                if (in_array($k, self::KEY_FIELDS, true)) {
                    $key[$k] = $v;
                    unset($direct[$k]);
                }
            }
        }
        if ($direct !== []) {
            $this->db->update('products', $direct + ['updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
            $o = $this->auditView(array_intersect_key($p, $direct));
            $n = $this->auditView($direct);
            $this->history((int) $p['id'], 'updated', null, null, $o, $n, $reason, null, $actor);
            App::activity()->log('product.updated', "Updated product {$p['product_code']}", 'product', (int) $p['id'], module: 'products', oldValues: $o, newValues: $n);
        }
        $change = null;
        if ($key !== []) {
            if (trim((string) $reason) === '') {
                throw ValidationException::withMessage('change_reason', 'Give a reason for changing key details.');
            }
            if ($this->pendingOfType((int) $p['id'], 'update') !== null) {
                throw new DomainRuleException('Another key-detail change is waiting for approval.');
            }
            $change = $this->request($p, 'update', $this->auditView(array_intersect_key($p, $key)), $key, $reason, $actor, 'products.edit');
        }
        return ['direct' => array_keys($direct), 'key' => array_keys($key), 'change' => $change];
    }

    /** Submit a draft for approval (applies at once when the workflow is inactive). @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function submit(array $p, array $actor): array
    {
        if ($p['status'] !== 'draft') {
            throw new DomainRuleException('Only draft products can be submitted.');
        }
        $this->assertActivatable($p);
        $this->db->update('products', ['status' => 'pending_approval', 'rejection_reason' => null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
        $this->history((int) $p['id'], 'status', 'draft', 'pending_approval', null, null, 'Submitted for approval', null, $actor);
        try {
            return $this->request($p, 'create', null, ['status' => 'active'], 'New product', $actor, 'products.create');
        } catch (\Throwable $e) {
            $this->db->update('products', ['status' => 'draft'], ['id' => $p['id']]);
            throw $e;
        }
    }

    // ---------------------------------------------------------------- status, SKU, archive

    /** @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function changeStatus(array $p, string $to, ?string $reason, array $actor): void
    {
        $this->assertWritable($p);
        if (!isset(self::TRANSITIONS[$to])) {
            throw ValidationException::withMessage('status', 'Choose a valid status.');
        }
        [$from, , $label] = self::TRANSITIONS[$to];
        if (!in_array($p['status'], $from, true)) {
            throw new DomainRuleException('A ' . strtolower(self::STATUSES[$p['status']] ?? $p['status']) . ' product cannot be changed to ' . strtolower(self::STATUSES[$to]) . '.');
        }
        $reason = trim((string) $reason);
        if ($to !== 'active' && $reason === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($to === 'active') {
            $this->assertActivatable($p);
        }
        $this->db->update('products', ['status' => $to, 'status_reason' => $reason ?: null, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
        $this->history((int) $p['id'], 'status', $p['status'], $to, null, null, $reason ?: null, BusinessDate::today(), $actor);
        App::activity()->log('product.status_changed', "{$label}: product {$p['product_code']} ({$p['status']} → {$to})", 'product', (int) $p['id'], module: 'products',
            oldValues: ['status' => $p['status']], newValues: ['status' => $to, 'reason' => $reason ?: null]);
    }

    /**
     * Change the SKU of a product (variant null) or one of its variants. An empty SKU means
     * "generate one". Drafts change at once; otherwise through the SKU change workflow.
     * @param array<string, mixed> $p @param array<string, mixed> $actor @return array<string, mixed>|null change request
     */
    public function requestSku(array $p, ?int $variantId, string $newSku, string $reason, array $actor): ?array
    {
        $this->assertWritable($p);
        $variant = null;
        if ($variantId !== null) {
            $variant = $this->db->first('SELECT * FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $p['id']]) ?? throw new DomainRuleException('Variant not found.');
            if ($variant['status'] === 'archived') {
                throw new DomainRuleException('Archived variants keep their SKU.');
            }
        }
        $skus = new SkuService($this->db);
        $newSku = SkuService::normalize($newSku);
        $generated = false;
        if ($newSku === '') {
            $newSku = $variant !== null
                ? $skus->generate((string) setting('product_variant_sku_pattern', '{PARENT}-{SEQ:2}'), $skus->variantTokens($p, (new VariantService($this->db))->valueLabels((int) $variant['id'])))
                : $skus->generate((string) setting('product_sku_pattern', '{CAT}-{SEQ:5}'), $skus->productTokens($p));
            $generated = true;
        } elseif (!setting('product_sku_manual_allowed', true)) {
            throw ValidationException::withMessage('sku', 'SKUs are generated automatically. Leave the field empty to generate a new one.');
        }
        $current = (string) ($variant !== null ? $variant['sku'] : $p['sku']);
        if ($newSku === $current) {
            throw ValidationException::withMessage('sku', 'That is already the SKU.');
        }
        $skus->assertAvailable($newSku);
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'Give a reason for the SKU change.');
        }
        $new = ['sku' => $newSku, 'generated' => $generated];
        if ($p['status'] === 'draft') {
            $this->applySku($p, $variantId, $new, $reason, $actor);
            return null;
        }
        if ($this->pendingOfType((int) $p['id'], 'sku', $variantId) !== null) {
            throw new DomainRuleException('A SKU change for this item is already waiting for approval.');
        }
        return $this->request($p, 'sku', ['sku' => $current ?: null], $new, $reason, $actor, 'products.manage_sku', $variantId);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $actor @return array<string, mixed> change request */
    public function requestArchive(array $p, string $reason, array $actor): array
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Already archived.');
        }
        if ($p['status'] === 'pending_approval') {
            throw new DomainRuleException('Decide or withdraw the pending approval first.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        if ($this->pendingOfType((int) $p['id'], 'archive') !== null) {
            throw new DomainRuleException('Archiving is already waiting for approval.');
        }
        return $this->request($p, 'archive', ['status' => $p['status']], ['status' => 'archived'], $reason, $actor, 'products.archive');
    }

    /** Restore an archived product to the status it had before. @param array<string, mixed> $p @param array<string, mixed> $actor */
    public function restore(array $p, array $actor): void
    {
        if ($p['status'] !== 'archived') {
            throw new DomainRuleException('This product is not archived.');
        }
        $to = (string) ($p['status_before_archive'] ?: 'inactive');
        if ($to === 'pending_approval') {
            $to = 'draft';
        }
        $this->db->update('products', ['status' => $to, 'status_before_archive' => null, 'archived_at' => null, 'archived_by' => null, 'archived_use_allowed' => 0,
            'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
        $this->history((int) $p['id'], 'restored', 'archived', $to, null, null, null, BusinessDate::today(), $actor);
        App::activity()->log('product.restored', "Restored product {$p['product_code']} (now {$to})", 'product', (int) $p['id'], module: 'products', oldValues: ['status' => 'archived'], newValues: ['status' => $to]);
    }

    /**
     * Explicit exception: allow an archived product in new transactions (e.g. selling off the
     * last stock). Audited with a reason. @param array<string, mixed> $p @param array<string, mixed> $actor
     */
    public function setArchivedUse(array $p, bool $allowed, string $reason, array $actor): void
    {
        if ($p['status'] !== 'archived') {
            throw new DomainRuleException('Only archived products need this exception.');
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessage('reason', 'A reason is required.');
        }
        $this->db->update('products', ['archived_use_allowed' => $allowed ? 1 : 0, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $p['id']]);
        $this->history((int) $p['id'], 'archived_use', null, null, ['archived_use_allowed' => (int) $p['archived_use_allowed']], ['archived_use_allowed' => $allowed ? 1 : 0], $reason, null, $actor);
        App::activity()->log('product.archived_use_changed', ($allowed ? 'Allowed' : 'Stopped') . " use of archived product {$p['product_code']} in new transactions", 'product', (int) $p['id'],
            module: 'products', newValues: ['archived_use_allowed' => $allowed, 'reason' => $reason]);
    }

    /**
     * For later phases (inventory, listings, orders, purchases): may this product / variant be
     * used in a new transaction? Archived ones only with the explicit exception.
     * @param array<string, mixed> $p @param array<string, mixed>|null $variant
     */
    public static function assertUsable(array $p, ?array $variant = null): void
    {
        $ok = $p['status'] === 'active' || ($p['status'] === 'archived' && (int) $p['archived_use_allowed'] === 1);
        if (!$ok) {
            throw new DomainRuleException("{$p['name']} is " . strtolower(self::STATUSES[$p['status']] ?? $p['status']) . ' and cannot be used in new transactions.');
        }
        if ($variant !== null && !in_array($variant['status'], ['active'], true) && !($p['status'] === 'archived' && (int) $p['archived_use_allowed'] === 1)) {
            throw new DomainRuleException("Variant {$variant['name']} is {$variant['status']} and cannot be used in new transactions.");
        }
    }

    /** @param array<string, mixed> $p */
    public function assertWritable(array $p): void
    {
        if ($p['status'] === 'archived') {
            throw new DomainRuleException('Archived products are read-only. Restore the product first.');
        }
    }

    // ---------------------------------------------------------------- approvals

    /**
     * Create a change request and submit it. Inactive workflows auto-approve, which applies it.
     * @param array<string, mixed> $p @param array<string, mixed>|null $old @param array<string, mixed> $new @param array<string, mixed> $actor
     * @return array<string, mixed>
     */
    public function request(array $p, string $type, ?array $old, array $new, ?string $reason, array $actor, string $permission, ?int $variantId = null): array
    {
        $id = $this->db->insert('product_change_requests', [
            'product_id' => (int) $p['id'], 'variant_id' => $variantId, 'change_type' => $type,
            'old_values' => $old === null ? null : json_encode($old, JSON_UNESCAPED_UNICODE), 'new_values' => json_encode($new, JSON_UNESCAPED_UNICODE),
            'reason' => $reason !== null ? mb_substr($reason, 0, 500) : null, 'status' => 'pending', 'requested_by' => (int) $actor['id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        App::activity()->log('product.change_requested', 'Requested ' . strtolower(self::TITLES[$type]) . " for product {$p['product_code']}", 'product', (int) $p['id'], module: 'products',
            oldValues: $old, newValues: $new + ['request' => $id, 'reason' => $reason]);
        try {
            $req = App::approvals()->submit(self::WORKFLOWS[$type], self::SUBJECT, $id, 0.0, self::TITLES[$type] . ": {$p['product_code']} — {$p['name']}", $actor, $permission);
        } catch (\Throwable $e) {
            $this->db->update('product_change_requests', ['status' => 'cancelled', 'decided_at' => now()], ['id' => $id]);
            throw $e;
        }
        $this->db->execute('UPDATE product_change_requests SET approval_request_id = ? WHERE id = ? AND approval_request_id IS NULL', [$req['id'], $id]);
        return $this->change($id) ?? [];
    }

    /** Approval engine callback. @param array<string, mixed> $request */
    public function applyDecision(array $request, string $status): void
    {
        $change = $this->change((int) $request['subject_id']);
        if ($change === null || $change['status'] !== 'pending') {
            return;
        }
        $last = $this->db->first('SELECT acted_by, comment FROM approval_actions WHERE request_id = ? ORDER BY id DESC LIMIT 1', [$request['id']]);
        $by = $last !== null ? ['id' => (int) $last['acted_by']] : ['id' => (int) $change['requested_by']];
        $p = $this->find((int) $change['product_id']);
        $new = match ($status) { 'approved' => 'approved', 'rejected' => 'rejected', 'cancelled' => 'cancelled', default => null };
        if ($new === null || $p === null) {
            return;
        }
        $this->db->transaction(function () use ($change, $p, $new, $by, $last, $request): void {
            $this->db->update('product_change_requests', ['status' => $new, 'approval_request_id' => (int) $request['id'], 'decided_at' => now(), 'updated_at' => now()], ['id' => $change['id']]);
            match ($change['change_type']) {
                'create' => $this->decideCreate($p, $new, $last['comment'] ?? null, $by),
                'update' => $new === 'approved' ? $this->applyUpdate($p, $change, $by) : null,
                'sku' => $new === 'approved' ? $this->applySku($p, $change['variant_id'] !== null ? (int) $change['variant_id'] : null, $change['new_values'], (string) $change['reason'], $by) : null,
                'archive' => $new === 'approved' ? $this->applyArchive($p, (string) $change['reason'], $by) : null,
                'preferred_supplier' => (new SupplierMappingService($this->db))->decidePreferred($p, $change, $new, $by),
            };
        });
        App::activity()->log('product.change_' . $new, ucfirst($new) . ' ' . strtolower(self::TITLES[$change['change_type']]) . " for product {$p['product_code']}", 'product', (int) $p['id'],
            module: 'products', newValues: ['request' => (int) $change['id'], 'status' => $new], userId: $by['id']);
    }

    /** @return array<string, mixed>|null */
    public function pendingOfType(int $productId, string $type, ?int $variantId = null): ?array
    {
        return $this->db->first("SELECT * FROM product_change_requests WHERE product_id = ? AND change_type = ? AND status = 'pending'"
            . ($type === 'sku' ? ($variantId === null ? ' AND variant_id IS NULL' : ' AND variant_id = ?') : '') . ' ORDER BY id DESC LIMIT 1',
            array_merge([$productId, $type], $type === 'sku' && $variantId !== null ? [$variantId] : []));
    }

    // ---------------------------------------------------------------- internals

    /** @param array<string, mixed> $p @param array<string, mixed> $by */
    private function decideCreate(array $p, string $status, ?string $comment, array $by): void
    {
        if ($p['status'] !== 'pending_approval') {
            return;
        }
        $to = $status === 'approved' ? 'active' : 'draft';
        $this->db->update('products', ['status' => $to, 'rejection_reason' => $status === 'rejected' ? $comment : null, 'updated_at' => now()], ['id' => $p['id']]);
        $this->history((int) $p['id'], 'status', 'pending_approval', $to, null, null, $status === 'rejected' ? 'Rejected: ' . ($comment ?? '') : ($status === 'cancelled' ? 'Request withdrawn' : 'Approved'), null, $by);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $change @param array<string, mixed> $by */
    private function applyUpdate(array $p, array $change, array $by): void
    {
        $new = array_intersect_key($change['new_values'], array_flip(self::KEY_FIELDS));
        // Re-check what may have changed while the request waited (e.g. a category archived).
        $merged = $new + array_intersect_key($p, array_flip(array_merge(self::KEY_FIELDS, self::DIRECT_FIELDS)));
        $this->validate($merged + ['tags' => $p['tags']], $p);
        $this->db->update('products', $new + ['updated_by' => $by['id'], 'updated_at' => now()], ['id' => $p['id']]);
        $this->history((int) $p['id'], 'updated', null, null, $change['old_values'], $this->auditView($new), $change['reason'], null, $by);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $new @param array<string, mixed> $by */
    private function applySku(array $p, ?int $variantId, array $new, string $reason, array $by): void
    {
        $skus = new SkuService($this->db);
        $sku = (string) $new['sku'];
        $skus->assertAvailable($sku);
        $old = $variantId !== null ? $this->db->scalar('SELECT sku FROM product_variants WHERE id = ?', [$variantId]) : $p['sku'];
        $skus->retire((int) $p['id'], $variantId);
        $skus->register($sku, (int) $p['id'], $variantId, !empty($new['generated']), (int) $by['id']);
        if ($variantId !== null) {
            $this->db->update('product_variants', ['sku' => $sku, 'updated_by' => $by['id'], 'updated_at' => now()], ['id' => $variantId]);
        } else {
            $this->db->update('products', ['sku' => $sku, 'updated_by' => $by['id'], 'updated_at' => now()], ['id' => $p['id']]);
        }
        $this->history((int) $p['id'], 'sku_changed', null, null, ['sku' => $old], ['sku' => $sku], $reason, null, $by, $variantId);
        App::activity()->log('product.sku_changed', "Changed SKU of {$p['product_code']}" . ($variantId ? " variant #{$variantId}" : '') . " from " . ($old ?: '(none)') . " to {$sku}", 'product', (int) $p['id'],
            module: 'products', oldValues: ['sku' => $old], newValues: ['sku' => $sku, 'variant_id' => $variantId], userId: $by['id']);
    }

    /** @param array<string, mixed> $p @param array<string, mixed> $by */
    private function applyArchive(array $p, string $reason, array $by): void
    {
        if ($p['status'] === 'archived') {
            return;
        }
        $this->db->update('products', ['status' => 'archived', 'status_before_archive' => $p['status'], 'status_reason' => mb_substr($reason, 0, 255), 'archived_at' => now(), 'archived_by' => $by['id'],
            'updated_at' => now()], ['id' => $p['id']]);
        $this->history((int) $p['id'], 'archived', $p['status'], 'archived', null, null, $reason, BusinessDate::today(), $by);
    }

    /** @param array<string, mixed> $p */
    private function assertActivatable(array $p): void
    {
        if (setting('product_tax_required_for_activation', false) && !(new TaxConfigService($this->db))->hasCurrent((int) $p['id'])) {
            throw new DomainRuleException('Add a GST / HSN configuration before activating this product (required by the product settings).');
        }
    }

    /** @param array<string, mixed>|null $old @param array<string, mixed>|null $new @param array<string, mixed> $actor */
    private function history(int $id, string $event, ?string $from, ?string $to, ?array $old, ?array $new, ?string $reason, ?string $date, array $actor, ?int $variantId = null): void
    {
        HistoryWriter::write($this->db, 'product_history', 'product_id', $id, $event, $from, $to, $old, $new, $reason, $date, $actor, $variantId);
    }

    /**
     * Validate input; returns normalised storable values.
     * @param array<string, mixed> $data @param array<string, mixed>|null $p @return array<string, mixed>
     */
    private function validate(array $data, ?array $p): array
    {
        foreach (['name', 'short_description', 'description', 'manufacturer', 'model_number', 'barcode', 'internal_notes', 'unit'] as $k) {
            $data[$k] = trim((string) ($data[$k] ?? ''));
        }
        $data['name'] = (string) preg_replace('/\s+/', ' ', $data['name']);
        $v = Validator::make($data, [
            'name' => 'required|string|min:2|max:200', 'short_description' => 'nullable|string|max:255', 'description' => 'nullable|string|max:10000',
            'product_type_id' => 'required|integer|exists:product_types,id', 'category_id' => 'nullable|integer|exists:product_categories,id',
            'subcategory_id' => 'nullable|integer|exists:product_categories,id', 'brand_id' => 'nullable|integer|exists:product_brands,id',
            'manufacturer' => 'nullable|string|max:150', 'model_number' => 'nullable|string|max:100', 'barcode' => 'nullable|string|max:50',
            'unit' => 'required|in:' . implode(',', array_keys(self::UNITS)), 'internal_notes' => 'nullable|string|max:5000',
        ], ['product_type_id' => 'Product type', 'category_id' => 'Category', 'subcategory_id' => 'Sub-category', 'brand_id' => 'Brand']);
        if ($v->fails()) {
            throw new ValidationException($v->errors());
        }
        $catalog = new CatalogService($this->db);
        $type = $this->db->first('SELECT * FROM product_types WHERE id = ?', [(int) $data['product_type_id']]);
        if (!(int) $type['is_active'] && (int) ($p['product_type_id'] ?? 0) !== (int) $type['id']) {
            throw ValidationException::withMessage('product_type_id', 'This product type is no longer active.');
        }
        $cat = (int) ($data['category_id'] ?? 0) ?: null;
        $sub = (int) ($data['subcategory_id'] ?? 0) ?: null;
        foreach (['category_id' => $cat, 'subcategory_id' => $sub] as $field => $id) {
            if ($id === null) {
                continue;
            }
            $c = $catalog->category($id);
            if (((int) $c['is_archived'] === 1 || $c['status'] !== 'active') && (int) ($p[$field] ?? 0) !== $id) {
                throw ValidationException::withMessage($field, "The category {$c['name']} is inactive or archived and cannot be chosen.");
            }
        }
        if ($sub !== null && $cat === null) {
            throw ValidationException::withMessage('category_id', 'Choose the main category for this sub-category.');
        }
        if ($sub !== null && !in_array($sub, $catalog->descendantIds((int) $cat), true)) {
            throw ValidationException::withMessage('subcategory_id', 'The sub-category must sit under the chosen category.');
        }
        $brand = (int) ($data['brand_id'] ?? 0) ?: null;
        if ($brand !== null) {
            $b = $catalog->brand($brand);
            if (((int) $b['is_archived'] === 1 || $b['status'] !== 'active') && (int) ($p['brand_id'] ?? 0) !== $brand) {
                throw ValidationException::withMessage('brand_id', "The brand {$b['name']} is inactive or archived and cannot be chosen.");
            }
        }
        $barcode = $data['barcode'] !== '' ? $data['barcode'] : null;
        $barcodeType = null;
        if ($barcode !== null) {
            $barcodeType = isset(Barcode::TYPES[(string) ($data['barcode_type'] ?? '')]) ? (string) $data['barcode_type'] : Barcode::detect($barcode);
            if (($err = Barcode::error($barcode, $barcodeType)) !== null) {
                throw ValidationException::withMessage('barcode', $err);
            }
            $clash = $this->db->scalar('SELECT product_code FROM products WHERE barcode = ? AND id <> ?', [$barcode, (int) ($p['id'] ?? 0)])
                ?: $this->db->scalar('SELECT p.product_code FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.barcode = ?', [$barcode]);
            if ($clash) {
                throw ValidationException::withMessage('barcode', "This barcode is already used by {$clash}.");
            }
        }
        $tags = array_values(array_unique(array_filter(array_map(static fn (string $t): string => mb_substr(mb_strtolower(trim($t)), 0, 40), explode(',', (string) ($data['tags'] ?? '')))))) ;
        if (count($tags) > 25) {
            throw ValidationException::withMessage('tags', 'Use at most 25 tags.');
        }
        $tracks = array_key_exists('tracks_inventory', $data) ? (!empty($data['tracks_inventory']) ? 1 : 0) : ($p !== null ? (int) $p['tracks_inventory'] : (int) $type['tracks_inventory']);
        if ((int) $type['is_service'] === 1) {
            $tracks = 0;
        }
        $n = static fn (string $k) => $data[$k] !== '' ? $data[$k] : null;
        return [
            'name' => $data['name'], 'short_description' => $n('short_description'), 'description' => $n('description'), 'product_type_id' => (int) $data['product_type_id'],
            'category_id' => $cat, 'subcategory_id' => $sub, 'brand_id' => $brand, 'manufacturer' => $n('manufacturer'), 'model_number' => $n('model_number'),
            'barcode' => $barcode, 'barcode_type' => $barcodeType, 'unit' => $data['unit'], 'tags' => $tags !== [] ? implode(', ', $tags) : null,
            'tracks_inventory' => $tracks, 'internal_notes' => $n('internal_notes'),
        ];
    }

    /** Values safe for history and audit. @param array<string, mixed> $row @return array<string, mixed> */
    public function auditView(array $row): array
    {
        return array_intersect_key($row, array_flip(array_merge(self::KEY_FIELDS, self::DIRECT_FIELDS, ['product_code', 'sku', 'status', 'uuid'])));
    }

    private function nextCode(): string
    {
        $max = 0;
        foreach ($this->db->select("SELECT product_code FROM products WHERE product_code LIKE 'PRD-%'") as $r) {
            $max = max($max, (int) substr((string) $r['product_code'], 4));
        }
        return 'PRD-' . str_pad((string) ($max + 1), 6, '0', STR_PAD_LEFT);
    }
}
