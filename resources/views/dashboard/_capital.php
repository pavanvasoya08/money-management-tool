<?php
/** Phase 3 partner widgets. Figures come from posted capital entries only. @var array<string, mixed> $capital */
$m = static fn (int $p): string => \App\Support\Money::format($p);
?>
<div class="section-title d-flex align-items-center gap-2 mb-2 mt-1"><i data-lucide="users-round" class="icon-sm"></i><span>Partners &amp; capital</span><span class="small text-secondary fw-normal">· posted entries only</span></div>
<div class="row g-3 mb-3">
    <?php if (isset($capital['partners'])): ?>
        <div class="col-sm-6 col-xl-3"><a class="stat-card" href="<?= e(route('partners.index')) ?>"><span class="stat-icon tone-navy"><i data-lucide="users-round"></i></span><span class="stat-label">Total partners</span><span class="stat-value"><?= (int) ($capital['partners']['total'] - $capital['partners']['archived']) ?></span></a></div>
        <div class="col-sm-6 col-xl-3"><a class="stat-card" href="<?= e(route('partners.index', ['status' => 'active'])) ?>"><span class="stat-icon tone-green"><i data-lucide="user-check"></i></span><span class="stat-label">Active partners</span><span class="stat-value text-success"><?= (int) $capital['partners']['active'] ?></span></a></div>
    <?php endif; ?>
    <?php foreach (['contribution' => ['Pending contributions', 'piggy-bank', 'capital.contributions.index'], 'withdrawal' => ['Pending withdrawals', 'hand-coins', 'capital.withdrawals.index']] as $type => [$label, $icon, $route]): if (!isset($capital['pending_' . $type])) { continue; } $pd = $capital['pending_' . $type]; ?>
        <div class="col-sm-6 col-xl-3"><a class="stat-card" href="<?= e(route($route, ['status' => 'pending'])) ?>"><span class="stat-icon tone-amber"><i data-lucide="<?= e($icon) ?>"></i></span><span class="stat-label"><?= e($label) ?></span>
            <span class="stat-value"><?= (int) $pd['count'] ?></span><span class="small text-secondary"><?= $pd['showAmount'] ? e($m((int) $pd['amount'])) . ' awaiting approval or posting' : 'Amounts hidden' ?></span></a></div>
    <?php endforeach; ?>
</div>
<?php if (isset($capital['totals'])): $t = $capital['totals']; ?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><a class="stat-card" href="<?= e(route('capital.ledger.index')) ?>"><span class="stat-icon tone-blue"><i data-lucide="arrow-down-to-line"></i></span><span class="stat-label">Total posted capital</span><span class="stat-value"><?= e($m($t['net_contributed'])) ?></span><span class="small text-secondary">Contributions, net of reversals</span></a></div>
    <div class="col-md-4"><a class="stat-card" href="<?= e(route('capital.ledger.index')) ?>"><span class="stat-icon tone-red"><i data-lucide="arrow-up-from-line"></i></span><span class="stat-label">Total posted withdrawals</span><span class="stat-value"><?= e($m($t['net_withdrawn'])) ?></span><span class="small text-secondary">Not a business expense</span></a></div>
    <div class="col-md-4"><a class="stat-card" href="<?= e(route('capital.ledger.index')) ?>"><span class="stat-icon tone-green"><i data-lucide="landmark"></i></span><span class="stat-label">Total outstanding capital</span><span class="stat-value"><?= e($m($t['closing'])) ?></span><span class="small text-secondary">Incl. opening balances &amp; adjustments</span></a></div>
</div>
<div class="card app-card mb-3">
    <div class="card-header d-flex justify-content-between"><span><i data-lucide="bar-chart-3" class="icon-sm"></i> Partner-wise capital distribution</span><span class="small text-secondary">Closing capital balance</span></div>
    <div class="card-body">
        <?php if ($capital['chart'] === null): ?>
            <?= $view->partial('partials/empty', ['icon' => 'bar-chart-3', 'text' => 'No posted capital yet. Balances appear here once contributions are approved and posted.']) ?>
        <?php else: ?>
            <div class="chart-box" data-height="<?= 60 + 34 * count($capital['bars']) ?>"><canvas id="capital-chart" role="img" data-chart="<?= e(json_encode($capital['chart'], JSON_THROW_ON_ERROR)) ?>" aria-label="Closing capital balance per partner"></canvas></div>
            <details class="mt-2 small"><summary class="text-secondary">Show as table</summary>
                <table class="table table-sm mt-2 mb-0"><thead><tr><th>Partner</th><th class="text-end">Closing capital</th></tr></thead><tbody>
                <?php foreach ($capital['bars'] as $b): ?><tr><td><?= e($b['label']) ?></td><td class="text-end"><?= e($m((int) $b['paise'])) ?></td></tr><?php endforeach; ?>
                </tbody></table>
            </details>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
