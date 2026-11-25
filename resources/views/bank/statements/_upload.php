<?php
/** Statement upload form. @var int|null $accountId @var list<array<string, mixed>> $accounts */
?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('bank.statements.store')) ?>" enctype="multipart/form-data" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <?php if (!empty($accountId)): ?><input type="hidden" name="bank_account_id" value="<?= (int) $accountId ?>"><?php else: ?>
        <div class="col-md-4"><label class="form-label small" for="s-acc">Bank account <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('bank_account_id') ?>" id="s-acc" name="bank_account_id" required><option value="">Choose…</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['display_name']) ?> · XXXXXX<?= e($a['account_number_last4']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-6 col-md-2"><label class="form-label small" for="s-from">From <span class="text-danger">*</span></label><input class="form-control<?= invalid('period_from') ?>" type="date" id="s-from" name="period_from" required></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="s-to">To <span class="text-danger">*</span></label><input class="form-control<?= invalid('period_to') ?>" type="date" id="s-to" name="period_to" required><?= $view->partial('partials/field-error', ['field' => 'period_to']) ?></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="s-ob">Opening balance</label><input class="form-control" id="s-ob" name="opening_balance" inputmode="decimal" placeholder="As printed"></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="s-cb">Closing balance</label><input class="form-control" id="s-cb" name="closing_balance" inputmode="decimal" placeholder="As printed"></div>
        <div class="col-md-<?= !empty($accountId) ? '4' : '6' ?>"><label class="form-label small" for="s-file">Statement file (CSV, PDF or XLSX, max 10 MB) <span class="text-danger">*</span></label>
            <input class="form-control<?= invalid('statement') ?>" type="file" id="s-file" name="statement" required accept=".csv,.pdf,.xlsx"><?= $view->partial('partials/field-error', ['field' => 'statement']) ?></div>
        <div class="col-md-<?= !empty($accountId) ? '6' : '4' ?>"><label class="form-label small" for="s-notes">Notes</label><input class="form-control" id="s-notes" name="notes" maxlength="500"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><i data-lucide="upload" class="icon-sm"></i>Upload</button></div>
    </div>
</form>
