<?php
/** @var \App\Core\Paginator $rows @var array<string, mixed> $f @var list<array<string, mixed>> $subjects */
$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Inventory history</h1><p>Insert-only record of every inventory change — warehouses, locations, settings, documents, batches, serials, reservations and valuation — with old and new values, reason, who and when.</p></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('inventory.history')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Event, reason or value" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="warehouse" aria-label="Warehouse"><option value="">All warehouses</option><?php foreach ($warehouses as $w): ?><option value="<?= (int) $w['id'] ?>" <?= $sel('warehouse', $w['id']) ?>><?= e($w['code'] . ' · ' . $w['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="subject" aria-label="Record"><option value="">Any record</option><?php foreach ($subjects as $s): ?><option value="<?= e($s['subject_type']) ?>" <?= $sel('subject', $s['subject_type']) ?>><?= e(ucfirst(str_replace('_', ' ', $s['subject_type']))) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From date"><input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To date">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('inventory.history')) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('inventory/_history', ['history' => $rows->items]) ?>
    <?= $view->partial('partials/pagination', ['paginator' => $rows]) ?>
</div>
