<?php
/** @var array<string, mixed> $s @var list<array<string, mixed>> $products @var list<array<string, mixed>> $recent @var array<string, mixed> $productFields @var array<string, int> $counts @var array<string, mixed>|null $businessAddress @var array<string, mixed> $fields */
use App\Services\Supplier\SupplierDetailService;
use App\Support\ProductView;
?>
<div class="row g-4">
    <div class="col-xxl-7">
        <h2 class="card-section-title">Products supplied</h2>
        <?php if (!can('products.view')): ?><p class="text-secondary small">You do not have access to products.</p>
        <?php elseif ($products === []): ?><p class="text-secondary small">Not linked to any product yet. <a href="<?= e(route('suppliers.show', ['id' => $s['id'], 'tab' => 'products'])) ?>">Products tab</a></p>
        <?php else: ?>
        <div class="table-responsive mb-2"><table class="table app-table mb-0 table-stack">
            <thead><tr><th>Product</th><th class="text-end">Purchase price</th><th></th></tr></thead>
            <tbody><?php foreach ($products as $m): ?><tr><td data-label="Product"><a href="<?= e(route('products.show', ['id' => $m['product_id']])) ?>"><?= e($m['product_name']) ?></a><span class="d-block small text-secondary"><?= e($m['variant_name'] ?? 'All variants') ?></span></td>
                <td data-label="Purchase price" class="text-end"><?= ProductView::price($m['purchase_price'], (string) ($m['purchase_currency'] ?? 'INR'), $productFields['purchase_cost'] ?? null) ?></td>
                <td class="text-end"><?= (int) $m['is_preferred'] ? '<span class="badge badge-soft-primary">Preferred</span>' : '' ?></td></tr><?php endforeach; ?></tbody>
        </table></div>
        <?php if ($counts['products'] > count($products)): ?><a class="small" href="<?= e(route('suppliers.show', ['id' => $s['id'], 'tab' => 'products'])) ?>">All <?= (int) $counts['products'] ?> products</a><?php endif; ?>
        <?php endif; ?>
    </div>
    <div class="col-xxl-5">
        <h2 class="card-section-title">At a glance</h2>
        <dl class="detail-grid mb-4">
            <div><dt>Addresses</dt><dd><?= (int) $counts['addresses'] ?></dd></div><div><dt>Documents</dt><dd><?= (int) $counts['documents'] ?></dd></div>
            <?php if (can('suppliers.view_bank_details')): ?><div><dt>Active bank accounts</dt><dd><?= (int) $counts['bank'] ?></dd></div><?php endif; ?><div><dt>Our GST registrations</dt><dd><?= (int) $counts['gst'] ?></dd></div>
            <?php if ($businessAddress !== null && (!isset($fields['addresses']) || $fields['addresses']->canView())): ?><div class="span-2"><dt><?= e(SupplierDetailService::ADDRESS_TYPES[$businessAddress['address_type']]) ?></dt><dd><?= e(isset($fields['addresses']) && !$fields['addresses']->showsRealValue() ? '••••••' : SupplierDetailService::format($businessAddress)) ?></dd></div><?php endif; ?>
            <div class="span-2"><dt>Stable identifier</dt><dd class="text-mono small"><?= e($s['uuid']) ?></dd></div>
        </dl>
        <h2 class="card-section-title">Recent changes</h2>
        <?php if ($recent === []): ?><p class="text-secondary small">No history yet.</p><?php else: ?>
        <ul class="activity-list"><?php foreach ($recent as $h): ?><li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span>
            <div class="min-w-0 flex-grow-1"><div><?= e(ucfirst(str_replace('_', ' ', $h['event']))) ?></div><div class="small text-secondary"><?= e($h['actor_name'] ?? 'System') ?><?= $h['reason'] ? ' · ' . e($h['reason']) : '' ?></div></div>
            <span class="small text-secondary text-nowrap"><?= e(time_ago($h['created_at'])) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </div>
</div>
