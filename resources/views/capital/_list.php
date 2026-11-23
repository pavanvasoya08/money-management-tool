<?php
/** Rows already passed through field rules. @var \App\Core\Paginator $entries @var string $prefix route prefix @var bool $showPartner */
?>
<?php if ($entries->items === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'receipt-text', 'text' => $emptyText ?? 'No entries yet.']) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table table-hover mb-0 table-stack">
    <thead><tr><th>Number</th><th>Date</th><?php if (!empty($showPartner)): ?><th>Partner</th><?php endif; ?><th><?= $type === 'adjustment' ? 'Type' : 'Method' ?></th><th class="text-end">Amount</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($entries->items as $t): $isRev = $t['type'] === 'reversal'; ?>
        <tr>
            <td data-label="Number"><a class="fw-semibold" href="<?= e(route($prefix . '.show', ['id' => $t['id']])) ?>"><?= e($t['transaction_no']) ?></a><?php if ($isRev): ?><div class="small text-secondary">Reversal</div><?php endif; ?></td>
            <td data-label="Date" class="text-nowrap"><?= e(format_date($t['transaction_date'])) ?></td>
            <?php if (!empty($showPartner)): ?><td data-label="Partner"><?= e($t['partner_name']) ?><div class="small text-secondary"><?= e($t['partner_code']) ?></div></td><?php endif; ?>
            <td data-label="<?= $type === 'adjustment' ? 'Type' : 'Method' ?>" class="small"><?= e($type === 'adjustment' ? (\App\Services\Partners\CapitalService::CATEGORIES[$t['adjustment_category']] ?? '—') . ($t['direction'] === 'credit' ? ' · increase' : ' · decrease') : (\App\Services\Partners\CapitalService::METHODS[$t['payment_method']] ?? '—')) ?></td>
            <td data-label="Amount" class="text-end text-nowrap"><?= $isRev ? '<span class="text-secondary">−</span>' : '' ?><?= $view->partial('partials/money-cell', ['row' => $t, 'key' => 'amount']) ?></td>
            <td data-label="Status"><?= $view->partial('partials/capital-status', ['status' => $t['status']]) ?></td>
            <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(route($prefix . '.show', ['id' => $t['id']])) ?>">Open</a></td>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?= $view->partial('partials/pagination', ['paginator' => $entries]) ?>
<?php endif; ?>
