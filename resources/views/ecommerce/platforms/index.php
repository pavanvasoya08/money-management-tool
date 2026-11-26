<?php
/** @var list<array<string, mixed>> $rows @var array<string, mixed> $f @var list<array<string, mixed>> $categories @var list<array<string, mixed>> $gsts */
use App\Services\Ecommerce\PlatformService;
use App\Support\EcommerceView;
use App\Support\Locale;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
$countries = array_unique(array_column($rows, 'country'));
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>E-commerce platforms</h1><p><?= count($rows) ?> found</p></div>
        <div class="page-actions">
            <?php if (can('platforms.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('ecommerce.platforms.export', array_filter($f))]) ?><?php endif; ?>
            <a class="btn btn-light" href="<?= e(route('ecommerce.categories')) ?>"><i data-lucide="tags" class="icon-sm"></i>Categories</a>
            <?php if (can('platforms.create')): ?><a class="btn btn-primary" href="<?= e(route('ecommerce.platforms.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Add platform</a><?php endif; ?></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('ecommerce.platforms.index')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name, code or website" aria-label="Search platforms"></div>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $sel('category', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="status" aria-label="Status"><option value="">All except archived</option><?php foreach (PlatformService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('status', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="connection" aria-label="Connection"><option value="">Any connection</option><?php foreach (PlatformService::CONNECTION as $k => $l): ?><option value="<?= e($k) ?>" <?= $sel('connection', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="country" aria-label="Country"><option value="">Any country</option><?php foreach (Locale::COUNTRIES as $k => $l): if (!in_array($k, $countries, true) && $f['country'] !== $k) { continue; } ?><option value="<?= e($k) ?>" <?= $sel('country', $k) ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <?php if ($gsts !== []): ?><select class="form-select toolbar-select" name="gst" aria-label="GST registration"><option value="">Any GSTIN</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>" <?= $sel('gst', $g['id']) ?>><?= e($g['gstin']) ?></option><?php endforeach; ?></select><?php endif; ?>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('ecommerce.platforms.index')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'store', 'text' => array_filter($f) ? 'No platforms match these filters.' : 'No platforms yet. Add the marketplaces, online stores and other channels the business sells through.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Platform</th><th>Category</th><th>Country · currency</th><th>Mappings</th><th>Configured</th><th>Status</th></tr></thead>
        <tbody><?php foreach ($rows as $p): ?><tr>
            <td data-label="Platform"><div class="d-flex align-items-center gap-2"><?= EcommerceView::logo($p) ?><div class="min-w-0"><a class="fw-semibold" href="<?= e(route('ecommerce.platforms.show', ['id' => $p['id']])) ?>"><?= e($p['name']) ?></a><span class="d-block small text-secondary text-mono"><?= e($p['platform_code']) ?></span></div></div></td>
            <td data-label="Category"><?= e($p['category_name']) ?></td>
            <td data-label="Country · currency"><?= e(Locale::COUNTRIES[$p['country']] ?? $p['country']) ?><span class="d-block small text-secondary"><?= e(str_replace(',', ', ', (string) $p['currencies'])) ?></span></td>
            <td data-label="Mappings" class="small text-nowrap"><?= (int) $p['gst_count'] ?> GSTIN<?= (int) $p['gst_count'] === 1 ? '' : 's' ?><span class="d-block text-secondary"><?= (int) $p['warehouse_count'] ?> warehouse<?= (int) $p['warehouse_count'] === 1 ? '' : 's' ?></span></td>
            <td data-label="Configured"><?= EcommerceView::readiness($p['readiness']) ?></td>
            <td data-label="Status"><?= EcommerceView::badge((string) $p['status']) ?><span class="d-block mt-1"><?= EcommerceView::badge((string) $p['connection_status']) ?></span></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
