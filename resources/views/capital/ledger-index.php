<div class="page-head"><div><h1>Capital balances</h1><p><?= e($report['meta'][0] ?? '') ?></p></div>
    <div class="page-actions"><?php if (can('partner_reports.export') && can('capital_ledger.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('partner-reports.export', ['report' => 'balances'])]) ?><?php endif; ?></div></div>
<div class="card app-card">
    <form class="table-toolbar" method="get" action="<?= e(route('capital.ledger.index')) ?>">
        <label class="small text-secondary" for="as-of">As of</label><input class="form-control toolbar-select" type="date" id="as-of" name="to" value="<?= e($filters['to']) ?>" max="<?= e(\App\Support\BusinessDate::today()) ?>">
        <select class="form-select toolbar-select" name="status" aria-label="Partner status"><option value="">All partners</option><?php foreach (\App\Services\Partners\PartnerService::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <button class="btn btn-outline-secondary" type="submit">Apply</button>
    </form>
    <?php $rows = $report['rows']; ?>
    <?php if ($rows === []): ?><?= $view->partial('partials/empty', ['icon' => 'book-text', 'text' => 'No partners with capital you can see.']) ?><?php else: ?>
    <?php $cols = $report['columns']; $cell = static fn (mixed $v): string => is_int($v) ? '<span class="' . ($v < 0 ? 'text-danger' : '') . '">' . e(\App\Support\Money::format($v)) . '</span>' : e((string) $v); ?>
    <div class="table-responsive"><table class="table app-table table-hover mb-0 table-stack">
        <thead><tr><?php foreach ($cols as $k => $c): ?><th class="<?= ($c['type'] ?? '') === 'money' ? 'text-end' : '' ?>"><?= e($c['label']) ?></th><?php endforeach; ?><th></th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?><tr><?php foreach ($cols as $k => $c): ?><td data-label="<?= e($c['label']) ?>" class="<?= ($c['type'] ?? '') === 'money' ? 'text-end text-nowrap' : '' ?>"><?= $cell($r[$k] ?? '') ?></td><?php endforeach; ?>
            <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(route('capital.ledger.show', ['id' => $r['partner_id']])) ?>">Ledger</a></td></tr><?php endforeach; ?></tbody>
        <tfoot><tr class="fw-semibold"><?php foreach ($cols as $k => $c): ?><td class="<?= ($c['type'] ?? '') === 'money' ? 'text-end text-nowrap' : '' ?>"><?= array_key_exists($k, $report['totals'] ?? []) ? $cell($report['totals'][$k]) : '' ?></td><?php endforeach; ?><td></td></tr></tfoot>
    </table></div>
    <?php endif; ?>
    <div class="card-footer bg-white small text-secondary"><?= e($report['notice'] ?? '') ?></div>
</div>
