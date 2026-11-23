<?php
$editing = $partner !== null;
$fa = $fieldAccess ?? [];
$locked = static fn (string $f): bool => $editing && isset($fa[$f]) && !$fa[$f]->canEdit();
$hidden = static fn (string $f): bool => $editing && isset($fa[$f]) && !$fa[$f]->canView();
$val = static fn (string $f): string => (string) old($f, $partner[$f] ?? ($f === 'country' ? 'India' : ''));
$input = function (string $f, string $label, string $type = 'text', array $attrs = []) use ($locked, $hidden, $val, $view): string {
    if ($hidden($f)) {
        return '';
    }
    $a = '';
    foreach ($attrs as $k => $v) {
        $a .= ' ' . $k . '="' . e($v) . '"';
    }
    return '<label class="form-label" for="' . $f . '">' . e($label) . (!empty($attrs['required']) ? ' <span class="text-danger">*</span>' : '') . ($locked($f) ? ' <span class="badge badge-soft-secondary">locked</span>' : '') . '</label>'
        . '<input class="form-control' . invalid($f) . '" type="' . $type . '" id="' . $f . '" ' . ($locked($f) ? 'disabled' : 'name="' . $f . '"') . ' value="' . e($val($f)) . '"' . $a . '>'
        . $view->partial('partials/field-error', ['field' => $f]);
};
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit partner' : 'Add partner' ?></h1><p><?= $editing ? e($partner['partner_code']) . ' · changes are recorded in the audit log.' : 'A unique partner ID and a zero-balance capital account are created automatically. No permissions are granted by adding a partner.' ?></p></div></div>
<form method="post" action="<?= e($editing ? route('partners.update', ['id' => $partner['id']]) : route('partners.store')) ?>" enctype="multipart/form-data" novalidate data-loading class="form-narrow-lg">
    <?= csrf_field() ?>
    <div class="card app-card mb-3"><div class="card-header">Identity</div><div class="card-body row g-3">
        <div class="col-md-8"><?= $input('name', 'Full name', 'text', ['required' => 'required', 'maxlength' => '150', 'autocomplete' => 'off']) ?></div>
        <div class="col-md-4"><?= $input('date_of_joining', 'Date of joining', 'date', ['required' => 'required', 'max' => \App\Support\BusinessDate::today()]) ?></div>
        <div class="col-md-4"><?= $input('pan_number', 'PAN (optional)', 'text', ['maxlength' => '10', 'placeholder' => 'ABCPE1234F', 'autocomplete' => 'off']) ?><?php if (!$hidden('pan_number')): ?><div class="form-text">Format: 5 letters, 4 digits, 1 letter.</div><?php endif; ?></div>
        <?php if (!$editing && can('partners.upload_documents')): ?><div class="col-md-8"><label class="form-label" for="photo">Profile photo (optional)</label><input class="form-control<?= invalid('document') ?>" type="file" id="photo" name="photo" accept="image/jpeg,image/png,image/webp"><div class="form-text">JPG, PNG or WEBP up to 5 MB. Stored privately.</div><?= $view->partial('partials/field-error', ['field' => 'document']) ?></div><?php endif; ?>
    </div></div>
    <div class="card app-card mb-3"><div class="card-header">Contact</div><div class="card-body row g-3">
        <div class="col-md-6"><?= $input('email', 'Email', 'email', ['maxlength' => '191']) ?></div>
        <div class="col-md-6"><?= $input('mobile', 'Mobile number', 'tel', ['maxlength' => '20', 'placeholder' => '+91 98765 43210']) ?></div>
        <div class="col-12"><?= $input('address', 'Street address', 'text', ['maxlength' => '255']) ?></div>
        <div class="col-md-4"><?= $input('city', 'City', 'text', ['maxlength' => '100']) ?></div>
        <div class="col-md-3"><?= $input('state', 'State', 'text', ['maxlength' => '100']) ?></div>
        <div class="col-md-3"><?= $input('country', 'Country', 'text', ['maxlength' => '100']) ?></div>
        <div class="col-md-2"><?= $input('pin_code', 'PIN code', 'text', ['maxlength' => '12', 'inputmode' => 'numeric']) ?></div>
    </div></div>
    <?php if (!$hidden('notes')): ?>
    <div class="card app-card mb-3"><div class="card-header">Notes</div><div class="card-body">
        <textarea class="form-control<?= invalid('notes') ?>" id="notes" <?= $locked('notes') ? 'disabled' : 'name="notes"' ?> rows="3" maxlength="5000" aria-label="Notes"><?= e($val('notes')) ?></textarea><?= $view->partial('partials/field-error', ['field' => 'notes']) ?>
    </div></div>
    <?php endif; ?>
    <div class="d-flex justify-content-end gap-2">
        <a class="btn btn-light" href="<?= e($editing ? route('partners.show', ['id' => $partner['id']]) : route('partners.index')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><span class="btn-label"><?= $editing ? 'Save changes' : 'Add partner' ?></span></button>
    </div>
</form>
