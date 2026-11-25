<?php
/** @var \App\Core\Paginator $rows */
use App\Support\ProductView;
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Supplier history</h1><p>Every change to the suppliers you can see — newest first. Bank account numbers are never written to history.</p></div></div>
    <?php if ($rows->items === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'history', 'text' => 'No history yet.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>When</th><th>Supplier</th><th>Event</th><th>Details</th><th>By</th></tr></thead>
        <tbody><?php foreach ($rows->items as $h): ?><tr>
            <td data-label="When" class="small text-nowrap"><?= e(format_datetime($h['created_at'])) ?></td>
            <td data-label="Supplier"><a href="<?= e(route('suppliers.show', ['id' => $h['supplier_id'], 'tab' => 'history'])) ?>"><?= e($h['supplier_name']) ?></a><span class="d-block small text-secondary"><?= e($h['supplier_code']) ?></span></td>
            <td data-label="Event"><?= e(ucfirst(str_replace('_', ' ', $h['event']))) ?><?= $h['to_status'] ? '<span class="d-block small">' . e(($h['from_status'] ? $h['from_status'] . ' → ' : '') . $h['to_status']) . '</span>' : '' ?></td>
            <td data-label="Details" class="small"><?= ProductView::values($h['new_values'] ?? $h['old_values']) ?><?= $h['reason'] ? '<span class="d-block text-secondary">' . e($h['reason']) . '</span>' : '' ?></td>
            <td data-label="By"><?= e($h['actor_name'] ?? 'System') ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
    <?php endif; ?>
</div>
