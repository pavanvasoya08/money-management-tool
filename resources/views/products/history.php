<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f */
use App\Support\ProductView;
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Product history</h1><p>Every change to the products you can see — newest first. Each product's History tab also shows its approvals and audit log.</p></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('products.history')) ?>">
        <select class="form-select toolbar-select" name="event" aria-label="Event"><option value="">All events</option><?php foreach (['created' => 'Created', 'updated' => 'Updated', 'status' => 'Status', 'sku' => 'SKU', 'price' => 'Prices', 'tax' => 'GST / HSN', 'variant' => 'Variants', 'supplier' => 'Suppliers', 'gst' => 'GST registrations', 'image' => 'Images', 'document' => 'Documents', 'archived' => 'Archived'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['event'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'history', 'text' => 'No history found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>When</th><th>Product</th><th>Event</th><th>Details</th><th>By</th></tr></thead>
        <tbody><?php foreach ($rows->items as $h): ?><tr>
            <td data-label="When" class="small text-nowrap"><?= e(format_datetime($h['created_at'])) ?></td>
            <td data-label="Product"><a href="<?= e(route('products.show', ['id' => $h['product_id'], 'tab' => 'history'])) ?>"><?= e($h['product_name']) ?></a><span class="d-block small text-secondary"><?= e($h['product_code']) ?><?= $h['variant_name'] ? ' · ' . e($h['variant_name']) : '' ?></span></td>
            <td data-label="Event"><?= e(ucfirst(str_replace('_', ' ', $h['event']))) ?><?= $h['to_status'] ? '<span class="d-block small">' . e(($h['from_status'] ? $h['from_status'] . ' → ' : '') . $h['to_status']) . '</span>' : '' ?></td>
            <td data-label="Details" class="small"><?= ProductView::values($h['new_values'] ?? $h['old_values']) ?><?= $h['reason'] ? '<span class="d-block text-secondary">' . e($h['reason']) . '</span>' : '' ?></td>
            <td data-label="By"><?= e($h['actor_name'] ?? 'System') ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
