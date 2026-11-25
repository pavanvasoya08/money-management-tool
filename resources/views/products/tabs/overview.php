<?php
/**
 * @var array<string, mixed> $p @var list<array<string, mixed>> $current @var array<string, mixed>|null $taxNow @var array<string, mixed>|null $preferred
 * @var list<array<string, mixed>> $recent @var list<array<string, mixed>> $components @var list<array<string, mixed>> $usedIn @var array<string, mixed> $fields @var array<string, int> $counts
 */
use App\Services\Product\PricingService;
use App\Services\Product\TaxConfigService;
use App\Support\Money;
use App\Support\ProductView;

$pick = static function (string $type) use ($current): ?array {
    foreach ($current as $r) {
        if ($r['price_type'] === $type && $r['variant_id'] === null && $r['supplier_id'] === null && $r['gst_registration_id'] === null && $r['sales_channel'] === null) {
            return $r;
        }
    }
    return null;
};
$taxAccess = $fields['tax_configuration'] ?? null;
?>
<div class="row g-3 mb-4">
    <?php foreach (['standard_selling' => 'Standard selling price', 'maximum_selling' => 'MRP', 'standard_purchase' => 'Standard purchase price'] as $type => $label): $r = $pick($type); ?>
        <div class="col-md-4"><div class="bank-money"><span class="stat-label"><?= e($label) ?></span><span class="bank-money-value"><?= $r !== null ? ProductView::price(Money::fromDb($r['amount']), (string) $r['currency'], $fields[PricingService::field($type)] ?? null) : '<span class="text-secondary">Not set</span>' ?></span>
            <?php if ($r !== null): ?><span class="small text-secondary">since <?= e(format_date($r['effective_from'])) ?></span><?php endif; ?></div></div>
    <?php endforeach; ?>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <h2 class="card-section-title">GST / HSN in force</h2>
        <?php if ($taxAccess && !$taxAccess->canView()): ?><p class="text-secondary">Hidden by your field permissions.</p>
        <?php elseif ($taxNow === null): ?><p class="text-warning small mb-3"><i data-lucide="alert-triangle" class="icon-sm"></i> No GST / HSN configuration yet. <a href="<?= e(route('products.show', ['id' => $p['id'], 'tab' => 'tax'])) ?>">Configure it</a>.</p>
        <?php else: ?>
            <dl class="detail-grid mb-3">
                <div><dt><?= $taxNow['sac_code'] ? 'SAC' : 'HSN' ?></dt><dd class="text-mono"><?= e($taxAccess && !$taxAccess->showsRealValue() ? '••••' : ($taxNow['hsn_code'] ?? $taxNow['sac_code'] ?? '—')) ?></dd></div>
                <div><dt>GST rate</dt><dd><?= e($taxAccess && !$taxAccess->showsRealValue() ? '••••' : ($taxNow['rate_name'] ?? TaxConfigService::APPLICABILITY[$taxNow['applicability']])) ?></dd></div>
                <div><dt>Tax category</dt><dd><?= e($taxNow['category_name']) ?></dd></div>
                <div><dt>Prices</dt><dd><?= $taxNow['price_inclusion'] === 'inclusive' ? 'Include GST' : 'Exclude GST' ?></dd></div>
            </dl>
        <?php endif; ?>
        <h2 class="card-section-title">Preferred supplier</h2>
        <?php if ($preferred === null): ?><p class="text-secondary small"><?= $counts['suppliers'] > 0 ? 'No preferred supplier chosen.' : 'No suppliers linked yet.' ?> <a href="<?= e(route('products.show', ['id' => $p['id'], 'tab' => 'suppliers'])) ?>">Suppliers</a></p>
        <?php else: ?><p><?= can('suppliers.view') && $preferred['supplier_visible'] ? '<a class="fw-semibold" href="' . e(route('suppliers.show', ['id' => $preferred['supplier_id']])) . '">' . e($preferred['supplier_name']) . '</a>' : '<b>' . e($preferred['supplier_name']) . '</b>' ?>
            <span class="small text-secondary"><?= e($preferred['supplier_code']) ?><?= $preferred['lead_time_days'] !== null ? ' · lead time ' . (int) $preferred['lead_time_days'] . ' days' : '' ?></span></p><?php endif; ?>
        <dl class="detail-grid">
            <div><dt>Variants</dt><dd><?= (int) $counts['variants'] ?></dd></div><div><dt>Suppliers</dt><dd><?= (int) $counts['suppliers'] ?></dd></div>
            <div><dt>GST registrations</dt><dd><?= (int) $counts['gst'] ?></dd></div><div><dt>Images / documents</dt><dd><?= (int) $counts['images'] ?> / <?= (int) $counts['documents'] ?></dd></div>
        </dl>
    </div>
    <div class="col-lg-6">
        <?php if ($components !== []): ?>
            <h2 class="card-section-title">Components</h2>
            <ul class="list-unstyled small mb-4"><?php foreach ($components as $c): ?><li><?= e(ProductView::qty($c['quantity'])) ?> × <a href="<?= e(route('products.show', ['id' => $c['component_product_id']])) ?>"><?= e($c['component_name']) ?></a><?= $c['variant_name'] ? ' – ' . e($c['variant_name']) : '' ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($usedIn !== []): ?>
            <h2 class="card-section-title">Part of</h2>
            <ul class="list-unstyled small mb-4"><?php foreach ($usedIn as $u): ?><li><a href="<?= e(route('products.show', ['id' => $u['id']])) ?>"><?= e($u['name']) ?></a> (× <?= e(ProductView::qty($u['quantity'])) ?>)</li><?php endforeach; ?></ul>
        <?php endif; ?>
        <h2 class="card-section-title">Recent changes</h2>
        <?php if ($recent === []): ?><p class="text-secondary small">No history yet.</p><?php else: ?>
        <ul class="activity-list">
            <?php foreach ($recent as $h): ?><li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span>
                <div class="min-w-0 flex-grow-1"><div><?= e(ucfirst(str_replace('_', ' ', $h['event']))) ?><?= $h['to_status'] ? ' → ' . e(\App\Services\Product\ProductService::STATUSES[$h['to_status']] ?? $h['to_status']) : '' ?></div>
                    <div class="small text-secondary"><?= e($h['actor_name'] ?? 'System') ?><?= $h['reason'] ? ' · ' . e($h['reason']) : '' ?></div></div>
                <span class="small text-secondary text-nowrap"><?= e(time_ago($h['created_at'])) ?></span></li><?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<?php if ($p['short_description'] || $p['description']): ?>
    <h2 class="card-section-title mt-4">Description</h2>
    <?php if ($p['short_description']): ?><p class="fw-semibold"><?= e($p['short_description']) ?></p><?php endif; ?>
    <?php if ($p['description']): ?><div class="text-pre-line"><?= e($p['description']) ?></div><?php endif; ?>
<?php endif; ?>
