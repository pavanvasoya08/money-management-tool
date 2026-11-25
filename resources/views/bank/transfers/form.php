<?php
/** @var list<array<string, mixed>> $accounts @var array<int, float> $available @var list<array<string, mixed>> $gsts @var list<array<string, mixed>> $activities @var string $today @var int $from @var int $to */
$opt = static function (array $accounts, int $selected) use ($available): string {
    $h = '<option value="">Choose…</option>';
    foreach ($accounts as $a) {
        $h .= '<option value="' . (int) $a['id'] . '"' . ((int) $selected === (int) $a['id'] ? ' selected' : '') . (isset($available[(int) $a['id']]) ? ' data-available="' . e((string) $available[(int) $a['id']]) . '"' : '') . ' data-overdraft="0">'
            . e($a['display_name'] . ' · ' . $a['bank_name'] . ' XXXXXX' . $a['account_number_last4'] . ((int) $a['is_central_funding'] ? ' · central' : '')) . '</option>';
    }
    return $h;
};
?>
<div class="page-head"><div><h1>New internal transfer</h1><p>Moves money between two of the company's own accounts. On approval, one debit (source) and one credit (destination) are posted together under the same transfer number.</p></div></div>
<div class="bank-flow mb-4" aria-hidden="true"><span>1 · Source</span><span>2 · Destination</span><span>3 · Amount &amp; details</span><span>4 · Balance check</span><span>5 · Approval</span><span>6 · Both sides posted</span></div>
<form method="post" action="<?= e(route('bank.transfers.store')) ?>" enctype="multipart/form-data" data-loading data-transfer-form>
    <?= csrf_field() ?>
    <div class="card app-card mb-4"><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label" for="from_account_id">From (source) <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('from_account_id') ?>" id="from_account_id" name="from_account_id" required><?= $opt($accounts, (int) old('from_account_id', $from)) ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'from_account_id']) ?><div class="form-text" data-available-text></div></div>
        <div class="col-md-6"><label class="form-label" for="to_account_id">To (destination) <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('to_account_id') ?>" id="to_account_id" name="to_account_id" required><?= $opt($accounts, (int) old('to_account_id', $to)) ?></select>
            <?= $view->partial('partials/field-error', ['field' => 'to_account_id']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="transfer_date">Transfer date <span class="text-danger">*</span></label><input class="form-control<?= invalid('transfer_date') ?>" type="date" id="transfer_date" name="transfer_date" value="<?= e((string) old('transfer_date', $today)) ?>" max="<?= e($today) ?>" required><?= $view->partial('partials/field-error', ['field' => 'transfer_date']) ?></div>
        <div class="col-6 col-md-3"><label class="form-label" for="amount">Amount (₹) <span class="text-danger">*</span></label><input class="form-control<?= invalid('amount') ?>" id="amount" name="amount" value="<?= e((string) old('amount')) ?>" inputmode="decimal" required placeholder="0.00">
            <?= $view->partial('partials/field-error', ['field' => 'amount']) ?><div class="form-text text-danger" data-over-warning hidden>More than the available balance — the transfer will be refused unless the account allows overdraft.</div>
            <?php if (error_for('duplicate_ok') !== null): ?><div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="duplicate_ok" name="duplicate_ok" value="1"><label class="form-check-label text-danger small" for="duplicate_ok">Not a duplicate — save anyway</label></div><?php endif; ?></div>
        <div class="col-md-6"><label class="form-label" for="reference_no">Reference / UTR</label><input class="form-control" id="reference_no" name="reference_no" value="<?= e((string) old('reference_no')) ?>" maxlength="100"></div>
        <div class="col-md-6"><label class="form-label" for="purpose">Purpose <span class="text-danger">*</span></label><input class="form-control<?= invalid('purpose') ?>" id="purpose" name="purpose" value="<?= e((string) old('purpose')) ?>" maxlength="255" required placeholder="e.g. Working capital for marketplace operations"><?= $view->partial('partials/field-error', ['field' => 'purpose']) ?></div>
        <div class="col-md-3"><label class="form-label" for="gst_registration_id">GST registration</label><select class="form-select" id="gst_registration_id" name="gst_registration_id"><option value="">—</option><?php foreach ($gsts as $g): ?><option value="<?= (int) $g['id'] ?>"><?= e($g['gstin']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="business_activity_id">Business activity</label><select class="form-select" id="business_activity_id" name="business_activity_id"><option value="">—</option><?php foreach ($activities as $a): ?><option value="<?= (int) $a['id'] ?>"><?= e($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-8"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="2000"><?= e((string) old('notes')) ?></textarea></div>
        <?php if (can('bank_accounts.manage_documents')): ?><div class="col-md-4"><label class="form-label" for="document">Supporting document</label><input class="form-control" type="file" id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp"></div><?php endif; ?>
    </div></div>
    <div class="form-actions-bar">
        <a class="btn btn-light" href="<?= e(route('bank.transfers.index')) ?>">Cancel</a>
        <button class="btn btn-light" type="submit" name="action" value="draft">Save as draft</button>
        <button class="btn btn-primary" type="submit" name="action" value="submit">Save &amp; submit</button>
    </div>
</form>
