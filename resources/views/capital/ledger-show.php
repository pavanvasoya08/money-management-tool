<div class="page-head"><div><h1>Capital ledger</h1><p><a href="<?= e(route('partners.show', ['id' => $partner['id']])) ?>"><?= e($partner['name']) ?></a> (<?= e($partner['partner_code']) ?>) · posted entries only</p></div>
    <div class="page-actions"><?php if (can('capital_ledger.export')): ?><?= $view->partial('partials/export-menu', ['url' => route('capital.ledger.export', ['id' => $partner['id']])]) ?><?php endif; ?></div></div>
<div class="card app-card">
    <form class="table-toolbar" method="get">
        <label class="small text-secondary" for="f-from">From</label><input class="form-control toolbar-select" type="date" id="f-from" name="from" value="<?= e($filters['from']) ?>">
        <label class="small text-secondary" for="f-to">To</label><input class="form-control toolbar-select" type="date" id="f-to" name="to" value="<?= e($filters['to']) ?>">
        <button class="btn btn-outline-secondary" type="submit">Apply</button>
    </form>
    <?= $view->partial('partials/report-table', ['result' => $report, 'rows' => $report['rows'], 'withTotals' => true]) ?>
</div>
