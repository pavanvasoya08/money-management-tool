<?php
/** Inventory history rows (insert-only). @var list<array<string, mixed>> $history @var array<string, string> $statuses */
use App\Support\ProductView;

$statuses ??= [];
?>
<?php if ($history === []): ?><?= $view->partial('partials/empty', ['icon' => 'history', 'text' => 'No history yet.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Event</th><th>Details</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
    <tbody><?php foreach ($history as $h): ?><tr>
        <td data-label="Event"><b><?= e(ucfirst(str_replace('_', ' ', (string) $h['event']))) ?></b>
            <span class="d-block small text-secondary"><?= e(ucfirst(str_replace('_', ' ', (string) $h['subject_type']))) ?> #<?= (int) $h['subject_id'] ?><?= !empty($h['warehouse_code']) ? ' · ' . e($h['warehouse_code']) : '' ?><?= !empty($h['product_name']) ? ' · ' . e($h['product_name']) : '' ?></span>
            <?php if ($h['to_status']): ?><span class="d-block small"><?= $h['from_status'] ? e($statuses[$h['from_status']] ?? str_replace('_', ' ', $h['from_status'])) . ' → ' : '' ?><?= e($statuses[$h['to_status']] ?? str_replace('_', ' ', $h['to_status'])) ?></span><?php endif; ?></td>
        <td data-label="Details" class="small"><?php if ($h['old_values']): ?><span class="text-secondary">Old:</span><br><?= ProductView::values($h['old_values']) ?><br><?php endif; ?><?= $h['new_values'] ? ($h['old_values'] ? '<span class="text-secondary">New:</span><br>' : '') . ProductView::values($h['new_values']) : '' ?></td>
        <td data-label="Reason" class="small"><?= e($h['reason'] ?? '—') ?><?= $h['effective_date'] ? '<span class="d-block text-secondary">dated ' . e(format_date($h['effective_date'])) . '</span>' : '' ?></td>
        <td data-label="By"><?= e($h['actor_name'] ?? 'System') ?></td><td data-label="When" class="small text-nowrap"><?= e(format_datetime($h['created_at'])) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
