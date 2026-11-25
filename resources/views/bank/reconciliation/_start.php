<?php
/** Start a reconciliation. @var int|null $accountId @var list<array<string, mixed>> $accounts @var list<array<string, mixed>> $statements */
$imported = array_values(array_filter($statements, static fn (array $s): bool => $s['import_status'] === 'imported' && $s['status'] === 'active'));
?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('bank.reconciliation.store')) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <?php if (!empty($accountId)): ?><input type="hidden" name="bank_account_id" value="<?= (int) $accountId ?>"><?php else: ?>
        <div class="col-md-3"><label class="form-label small" for="r-acc">Bank account <span class="text-danger">*</span></label><select class="form-select" id="r-acc" name="bank_account_id" required><option value="">Choose…</option><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['display_name']) ?></option><?php endforeach; ?></select></div>
        <?php endif; ?>
        <div class="col-md-<?= !empty($accountId) ? '4' : '3' ?>"><label class="form-label small" for="r-st">Imported statement</label><select class="form-select" id="r-st" name="statement_id"><option value="">— choose to use its period and closing balance —</option>
            <?php foreach ($imported as $s): ?><option value="<?= (int) $s['id'] ?>" data-from="<?= e($s['period_from']) ?>" data-to="<?= e($s['period_to']) ?>" data-closing="<?= e((string) ($s['closing_balance'] ?? '')) ?>"><?= e(($accountId ? '' : $s['account_name'] . ' · ') . format_date($s['period_from']) . ' – ' . format_date($s['period_to'])) ?></option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="r-from">From <span class="text-danger">*</span></label><input class="form-control<?= invalid('period_from') ?>" type="date" id="r-from" name="period_from" required></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="r-to">To <span class="text-danger">*</span></label><input class="form-control<?= invalid('period_to') ?>" type="date" id="r-to" name="period_to" required></div>
        <div class="col-6 col-md-2"><label class="form-label small" for="r-cb">Statement closing</label><input class="form-control" id="r-cb" name="statement_closing_balance" inputmode="decimal"></div>
        <div class="col-6 col-md-<?= !empty($accountId) ? '2' : '12 col-xl-auto' ?>"><button class="btn btn-primary w-100" type="submit">Start reconciliation</button></div>
        <?= $view->partial('partials/field-error', ['field' => 'period_from']) ?>
    </div>
</form>
