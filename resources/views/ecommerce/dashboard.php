<?php
/**
 * E-commerce dashboard: platform-master figures only — no sales, orders, revenue or fake figures.
 * @var array<string, mixed> $d @var array<string, mixed> $f @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $options
 */
use App\Services\Ecommerce\PlatformHistory;
use App\Services\Ecommerce\PlatformService;
use App\Support\EcommerceView;

$t = $d['totals'];
$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$filtered = array_filter($f) !== [];
$list = static fn (array $q = []): string => route('ecommerce.platforms.index', $q);
$tiles = [
    ['Total platforms', $t['total'], 'store', 'tone-blue', $list(['archived' => 'with']), 'Including archived'],
    ['Active', $t['active'], 'circle-check', 'tone-green', $list(['status' => 'active']), null],
    ['Inactive', $t['inactive'], 'circle-pause', 'tone-amber', $list(['status' => 'inactive']), null],
    ['Under configuration', $t['under_configuration'], 'settings-2', 'tone-violet', $list(['status' => 'under_configuration']), null],
    ['Need configuration', $t['needs_configuration'], 'triangle-alert', 'tone-amber', $list(), 'Checklist not complete'],
    ['Archived', $t['archived'], 'archive', 'tone-slate', $list(['status' => 'archived']), null],
];
?>
<div class="page-head">
    <div><h1>E-commerce</h1><p>Sales platforms and marketplaces the business uses. Seller accounts, integrations and orders arrive in later phases.</p></div>
    <div class="page-actions">
        <a class="btn btn-light" href="<?= e(route('ecommerce.platforms.index')) ?>"><i data-lucide="store" class="icon-sm"></i>All platforms</a>
        <?php if (can('platforms.create')): ?><a class="btn btn-primary" href="<?= e(route('ecommerce.platforms.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add platform</a><?php endif; ?>
    </div>
</div>

<form class="card app-card mb-4" method="get" action="<?= e(route('ecommerce.dashboard')) ?>" role="search">
    <div class="card-body row g-2 align-items-end">
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-pl">Platform</label><select class="form-select" id="f-pl" name="platform"><option value="">All platforms</option><?php foreach ($options as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $sel('platform', $o['id']) ?>><?= e($o['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-cat">Category</label><select class="form-select" id="f-cat" name="category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-st">Status</label><select class="form-select" id="f-st" name="status"><option value="">Any status</option><?php foreach (PlatformService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-cn">Connection</label><select class="form-select" id="f-cn" name="connection"><option value="">Any connection</option><?php foreach (PlatformService::CONNECTION as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('connection', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-from">Added from</label><input class="form-control" type="date" id="f-from" name="from" value="<?= e($f['from']) ?>"></div>
        <div class="col-6 col-md-4 col-xl-2"><label class="form-label small" for="f-to">to</label><input class="form-control" type="date" id="f-to" name="to" value="<?= e($f['to']) ?>"></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button><?php if ($filtered): ?><a class="btn btn-link text-secondary" href="<?= e(route('ecommerce.dashboard')) ?>">Clear</a><?php endif; ?></div>
    </div>
</form>

<div class="card app-card mb-4">
    <div class="card-header">Platforms</div>
    <div class="card-body"><div class="kpi-group product-kpis">
        <?php foreach ($tiles as [$label, $value, $icon, $tone, $url, $hint]): ?>
            <a class="stat-card" href="<?= e($url) ?>" <?= $hint ? 'title="' . e($hint) . '"' : '' ?>><span class="stat-icon <?= e($tone) ?>"><i data-lucide="<?= e($icon) ?>"></i></span><span class="stat-label"><?= e($label) ?></span><span class="stat-value"><?= number_format((int) $value) ?></span><?php if ($hint): ?><span class="stat-hint small text-secondary"><?= e($hint) ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap gap-3 small text-secondary mt-3">
        <span><i data-lucide="plug" class="icon-sm"></i> Connections: <?= $d['hasIntegrationData'] ? (int) $t['connected'] . ' connected · ' . (int) $t['disconnected'] . ' disconnected' : 'no platform is connected — marketplace integrations are not available yet' ?></span>
        <span><i data-lucide="user-round" class="icon-sm"></i> Seller accounts: added in Phase 8B</span>
        <span><i data-lucide="receipt" class="icon-sm"></i> Orders and sales: Phase 9</span>
    </div></div>
</div>

<?php if ($t['total'] === 0): ?>
<div class="card app-card mb-4"><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'store', 'text' => $filtered ? 'No platforms match these filters.' : 'No platforms yet. Add the marketplaces, online stores and other channels the business sells through.']) ?></div></div>
<?php else: ?>
<div class="row g-4 mb-4">
    <div class="col-xl-8"><div class="card app-card h-100">
        <div class="card-header">Configuration status<a class="small fw-normal ms-auto" href="<?= e(route('ecommerce.platforms.index')) ?>">All platforms</a></div>
        <?php if ($d['summary'] === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'store', 'text' => 'Only archived platforms match.']) ?></div><?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Platform</th><th>Status</th><th>Configured</th><th>Linked</th></tr></thead><tbody>
            <?php foreach ($d['summary'] as $r): $c = $r['counts']; ?><tr>
                <td data-label="Platform"><a class="fw-semibold" href="<?= e(route('ecommerce.platforms.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a><span class="d-block small text-secondary"><?= e($r['category_name']) ?></span></td>
                <td data-label="Status"><?= EcommerceView::badge((string) $r['status']) ?></td>
                <td data-label="Configured"><?= EcommerceView::readiness($r['readiness']) ?></td>
                <td data-label="Linked" class="small"><?= (int) $c['gst'] ?> GSTIN · <?= (int) $c['warehouses'] ?> warehouse<?= (int) $c['warehouses'] === 1 ? '' : 's' ?><span class="d-block text-secondary"><?= (int) $c['activities'] ?> activit<?= (int) $c['activities'] === 1 ? 'y' : 'ies' ?> · <?= (int) $c['documents'] ?> document<?= (int) $c['documents'] === 1 ? '' : 's' ?></span></td></tr><?php endforeach; ?>
        </tbody></table></div><?php endif; ?>
    </div></div>
    <div class="col-xl-4"><div class="card app-card h-100">
        <div class="card-header">Platforms by category</div>
        <div class="card-body"><?php if ($d['byCategory'] === []): ?><p class="small text-secondary mb-0">Only archived platforms match.</p><?php else: $max = max(1, max($d['byCategory'])); ?><ul class="list-unstyled mb-0 bar-list">
            <?php foreach ($d['byCategory'] as $name => $n): ?><li class="mb-2"><div class="d-flex justify-content-between small"><span><?= e($name) ?></span><span><?= (int) $n ?></span></div>
                <div class="progress progress-thin" role="presentation"><div class="progress-bar" data-width="<?= max(2, (int) round($n * 100 / $max)) ?>"></div></div></li><?php endforeach; ?>
        </ul><?php endif; ?></div>
    </div></div>
</div>

<div class="row g-4">
    <div class="col-xl-5"><div class="card app-card h-100">
        <div class="card-header">Recently added</div>
        <ul class="activity-list px-3 py-2 mb-0"><?php foreach ($d['recent'] as $r): ?>
            <li><?= EcommerceView::logo($r) ?><div class="min-w-0 flex-grow-1"><div><a class="fw-semibold" href="<?= e(route('ecommerce.platforms.show', ['id' => $r['id']])) ?>"><?= e($r['name']) ?></a> <?= EcommerceView::badge((string) $r['status']) ?></div>
                <div class="small text-secondary"><?= e($r['category_name']) ?> · added <?= e(time_ago($r['created_at'])) ?></div></div></li>
        <?php endforeach; ?></ul>
    </div></div>
    <div class="col-xl-7"><div class="card app-card h-100">
        <div class="card-header">Recent platform activity<?php if (can('platforms.view_history')): ?><a class="small fw-normal ms-auto" href="<?= e(route('ecommerce.history')) ?>">History</a><?php endif; ?></div>
        <?php if ($d['activity'] === [] || !can('platforms.view_history')): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'history', 'text' => can('platforms.view_history') ? 'No activity in this period.' : 'History is not available to you.']) ?></div><?php else: ?>
        <ul class="activity-list px-3 py-2 mb-0"><?php foreach ($d['activity'] as $h): ?>
            <li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="min-w-0 flex-grow-1"><div><b><?= e($h['actor_name'] ?? 'System') ?></b> · <?= e(PlatformHistory::label($h['event'])) ?> — <a href="<?= e(route('ecommerce.platforms.show', ['id' => $h['platform_id']])) ?>"><?= e($h['platform_name']) ?></a></div>
                <div class="small text-secondary"><?= e(time_ago($h['created_at'])) ?><?= $h['reason'] ? ' · ' . e($h['reason']) : '' ?></div></div></li>
        <?php endforeach; ?></ul><?php endif; ?>
    </div></div>
</div>
<?php endif; ?>
