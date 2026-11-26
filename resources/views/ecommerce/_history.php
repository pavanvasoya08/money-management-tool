<?php
/**
 * Platform history rows (insert-only).
 * @var list<array<string, mixed>> $history @var bool $showPlatform @var bool $seePartners
 */
use App\Services\Ecommerce\PlatformHistory;
use App\Services\Ecommerce\PlatformService;
use App\Support\ProductView;

$statuses = PlatformService::STATUSES;
?>
<?php if ($history === []): ?><?= $view->partial('partials/empty', ['icon' => 'history', 'text' => 'No history yet.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><?php if ($showPlatform): ?><th>Platform</th><?php endif; ?><th>Event</th><th>Details</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
    <tbody><?php foreach ($history as $h): $hidden = !$seePartners && str_starts_with((string) $h['event'], 'partner_'); ?><tr>
        <?php if ($showPlatform): ?><td data-label="Platform"><a href="<?= e(route('ecommerce.platforms.show', ['id' => $h['platform_id']])) ?>"><?= e($h['platform_name']) ?></a><span class="d-block small text-secondary text-mono"><?= e($h['platform_code']) ?></span></td><?php endif; ?>
        <td data-label="Event"><b><?= e(PlatformHistory::label((string) $h['event'])) ?></b>
            <?php if ($h['to_status']): ?><span class="d-block small"><?= $h['from_status'] ? e($statuses[$h['from_status']] ?? $h['from_status']) . ' → ' : '' ?><?= e($statuses[$h['to_status']] ?? $h['to_status']) ?></span><?php endif; ?></td>
        <td data-label="Details" class="small"><?php if ($hidden): ?><span class="text-secondary">Hidden by your field permissions</span><?php else: ?><?php if ($h['old_values']): ?><span class="text-secondary">Old:</span><br><?= ProductView::values($h['old_values']) ?><br><?php endif; ?><?= $h['new_values'] ? ($h['old_values'] ? '<span class="text-secondary">New:</span><br>' : '') . ProductView::values($h['new_values']) : '' ?><?php endif; ?></td>
        <td data-label="Reason" class="small"><?= e($h['reason'] ?? '—') ?><?= $h['effective_date'] ? '<span class="d-block text-secondary">dated ' . e(format_date($h['effective_date'])) . '</span>' : '' ?></td>
        <td data-label="By"><?= e($h['actor_name'] ?? 'System') ?></td><td data-label="When" class="small text-nowrap"><?= e(format_datetime($h['created_at'])) ?></td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
