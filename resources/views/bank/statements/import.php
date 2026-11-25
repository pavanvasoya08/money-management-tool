<?php
/** CSV column mapping. @var array<string, mixed> $st @var list<list<string>> $sample @var string $delimiter @var array<string, int|null> $guess @var int $columns */
use App\Services\Bank\BankStatementService;

$isCsv = str_ends_with(strtolower((string) $st['original_name']), '.csv');
?>
<div class="page-head"><div><h1>Import statement lines</h1><p><?= e($st['account_name']) ?> · <?= e(format_date($st['period_from'])) ?> – <?= e(format_date($st['period_to'])) ?> · <?= e($st['original_name']) ?></p></div></div>
<?php if (!$isCsv): ?>
    <div class="alert alert-info">Only CSV statements can be imported. This file is kept as a document; download your bank's CSV export to import lines.</div>
<?php elseif ($st['import_status'] === 'imported'): ?>
    <div class="alert alert-success">This statement has already been imported. <a href="<?= e(route('bank.statements.show', ['id' => $st['id']])) ?>">View the imported lines</a>.</div>
<?php else: ?>
<div class="alert alert-info small"><i data-lucide="info" class="icon-sm"></i> Step 1: tell us which column holds what. Step 2: check the preview. Step 3: import. Importing only stores the lines for reconciliation — it never creates, changes or posts ledger entries, and lines already imported for this account are skipped as duplicates.</div>
<form class="card app-card mb-4" method="get" action="<?= e(route('bank.statements.import', ['id' => $st['id']])) ?>">
    <div class="card-body d-flex flex-wrap gap-2 align-items-end">
        <div><label class="form-label small" for="i-delim">Column separator</label><select class="form-select" id="i-delim" name="delimiter"><?php foreach (BankStatementService::DELIMITERS as $k => $l): ?><option value="<?= e($k) ?>" <?= $delimiter === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <button class="btn btn-outline-secondary" type="submit">Re-read file</button>
    </div>
</form>
<?php if ($sample === []): ?><?= $view->partial('partials/empty', ['icon' => 'file-spreadsheet', 'text' => 'The file is empty.']) ?><?php else: ?>
<div class="card app-card mb-4">
    <div class="card-header">First lines of the file</div>
    <div class="table-responsive"><table class="table table-sm mb-0 bank-csv-sample">
        <thead><tr><?php for ($i = 0; $i < $columns; $i++): ?><th class="text-secondary small">Column <?= $i + 1 ?></th><?php endfor; ?></tr></thead>
        <tbody><?php foreach ($sample as $line): ?><tr><?php for ($i = 0; $i < $columns; $i++): ?><td class="small"><?= e(mb_substr($line[$i] ?? '', 0, 60)) ?></td><?php endfor; ?></tr><?php endforeach; ?></tbody>
    </table></div>
</div>
<form class="card app-card mb-4" method="post" action="<?= e(route('bank.statements.preview', ['id' => $st['id']])) ?>" data-loading>
    <div class="card-header">Column mapping</div>
    <div class="card-body row g-3"><?= csrf_field() ?>
        <input type="hidden" name="delimiter" value="<?= e($delimiter) ?>">
        <?php foreach (BankStatementService::FIELDS as $field => $label): ?>
            <div class="col-sm-6 col-lg-3"><label class="form-label small" for="m-<?= e($field) ?>"><?= e($label) ?><?= $field === 'date' ? ' <span class="text-danger">*</span>' : '' ?></label>
                <select class="form-select" id="m-<?= e($field) ?>" name="map[<?= e($field) ?>]"><option value="">— not in file —</option>
                    <?php for ($i = 0; $i < $columns; $i++): ?><option value="<?= $i ?>" <?= ($guess[$field] ?? null) === $i ? 'selected' : '' ?>>Column <?= $i + 1 ?>: <?= e(mb_substr($sample[0][$i] ?? '', 0, 30)) ?></option><?php endfor; ?></select></div>
        <?php endforeach; ?>
        <div class="col-sm-6 col-lg-3"><label class="form-label small" for="m-df">Date format <span class="text-danger">*</span></label><select class="form-select" id="m-df" name="date_format"><?php foreach (BankStatementService::DATE_FORMATS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($guessFormat ?? null) === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><div class="form-check"><input type="hidden" name="has_header" value="0"><input class="form-check-input" type="checkbox" id="m-head" name="has_header" value="1" checked><label class="form-check-label" for="m-head">The first line contains column names</label></div>
            <?= $view->partial('partials/field-error', ['field' => 'map']) ?>
            <div class="form-text">Map either <b>Debit</b> and <b>Credit</b>, or one <b>Signed amount</b> column (negative = debit). Amounts like 1,23,456.78, ₹ signs and Dr/Cr suffixes are understood.</div></div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-end gap-2"><a class="btn btn-light" href="<?= e(route('bank.statements.show', ['id' => $st['id']])) ?>">Cancel</a><button class="btn btn-primary" type="submit">Preview lines</button></div>
</form>
<?php endif; ?>
<?php endif; ?>
