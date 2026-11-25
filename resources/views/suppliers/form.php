<?php
/**
 * Add / edit a supplier. @var array<string, mixed>|null $s @var list<array<string, mixed>> $types @var array<string, string> $treatments @var array<string, string> $currencies
 * @var array<string, mixed> $fields @var list<string> $gstFields @var list<array<string, mixed>> $states
 */
$editing = $s !== null;
$isDraft = !$editing || $s['status'] === 'draft';
$val = static fn (string $k, mixed $default = ''): string => (string) old($k, $s[$k] ?? $default);
$req = '<span class="text-danger">*</span>';
$can = static fn (string $f): bool => !isset($fields[$f]) || $fields[$f]->canEdit();
$see = static fn (string $f): bool => !isset($fields[$f]) || $fields[$f]->canView();
$ro = static fn (string $f): string => $can($f) ? '' : ' disabled';
$gstLocked = $editing && !can('suppliers.manage_gst_details');
$key = static fn (string $k): string => $editing && !$isDraft && in_array($k, $gstFields, true) ? ' <span class="badge badge-soft-info" title="Changing this needs approval when the GSTIN change workflow is active">Needs approval</span>' : '';
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit supplier' : 'Add supplier' ?></h1>
    <p><?= $editing ? e($s['supplier_code'] . ' · ' . $s['name']) : 'New suppliers start as a draft and become active once approved (or at once when no approval is configured).' ?></p></div></div>
<?php if ($editing && !empty($s['rejection_reason'])): ?><div class="alert alert-danger small"><b>Approval was rejected:</b> <?= e($s['rejection_reason']) ?></div><?php endif; ?>
<form method="post" action="<?= e($editing ? route('suppliers.update', ['id' => $s['id']]) : route('suppliers.store')) ?>" data-loading data-gstin-form="<?= e(route('suppliers.check-gstin')) ?>" data-except="<?= (int) ($s['id'] ?? 0) ?>">
    <?= csrf_field() ?>
    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card app-card mb-4">
                <div class="card-header">Business information</div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label" for="name">Supplier name <?= $req ?></label><input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="200" required placeholder="Name used day to day"><?= $view->partial('partials/field-error', ['field' => 'name']) ?></div>
                    <div class="col-md-6"><label class="form-label" for="legal_name">Legal business name<?= $key('legal_name') ?></label><input class="form-control<?= invalid('legal_name') ?>" id="legal_name" name="legal_name" value="<?= e($val('legal_name')) ?>" maxlength="200" placeholder="As registered (empty = same as name)" <?= $gstLocked ? 'disabled' : '' ?>></div>
                    <div class="col-md-6"><label class="form-label" for="supplier_type_id">Supplier type <?= $req ?></label><select class="form-select<?= invalid('supplier_type_id') ?>" id="supplier_type_id" name="supplier_type_id" required><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $val('supplier_type_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-md-6"><label class="form-label" for="website">Website</label><input class="form-control<?= invalid('website') ?>" id="website" name="website" value="<?= e($val('website')) ?>" maxlength="255" placeholder="https://"><?= $view->partial('partials/field-error', ['field' => 'website']) ?></div>
                </div>
            </div>
            <div class="card app-card mb-4">
                <div class="card-header">GST details</div>
                <div class="card-body row g-3">
                    <div class="col-md-4"><label class="form-label" for="gst_treatment">GST treatment <?= $req ?><?= $key('gst_treatment') ?></label><select class="form-select<?= invalid('gst_treatment') ?>" id="gst_treatment" name="gst_treatment" <?= $gstLocked ? 'disabled' : '' ?>><?php foreach ($treatments as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('gst_treatment', 'registered') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
                    <?php if ($see('gstin')): ?><div class="col-md-5"><label class="form-label" for="gstin">GSTIN<?= $key('gstin') ?></label><input class="form-control text-mono text-uppercase<?= invalid('gstin') ?>" id="gstin" name="gstin" value="<?= e($val('gstin')) ?>" maxlength="15" autocomplete="off" data-gstin-input <?= $gstLocked || !$can('gstin') ? 'disabled' : '' ?>>
                        <div class="form-text" data-gstin-result>Checked as you type: structure, state code, PAN segment and check character. This is not a GST portal verification.</div><?= $view->partial('partials/field-error', ['field' => 'gstin']) ?></div><?php endif; ?>
                    <?php if ($see('pan')): ?><div class="col-md-3"><label class="form-label" for="pan">PAN<?= $key('pan') ?></label><input class="form-control text-mono text-uppercase<?= invalid('pan') ?>" id="pan" name="pan" value="<?= e($val('pan')) ?>" maxlength="10" placeholder="From GSTIN if empty" <?= $gstLocked || !$can('pan') ? 'disabled' : '' ?>><?= $view->partial('partials/field-error', ['field' => 'pan']) ?></div><?php endif; ?>
                    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="duplicate_ok" name="duplicate_ok" value="1"><label class="form-check-label small" for="duplicate_ok">I confirm this is a separate supplier even if another supplier has the same PAN (e.g. another state registration)</label></div><?= $view->partial('partials/field-error', ['field' => 'duplicate_ok']) ?></div>
                    <?php if ($editing && !$isDraft && !$gstLocked): ?><div class="col-12"><label class="form-label small" for="change_reason">Reason for GST-detail changes</label><input class="form-control<?= invalid('change_reason') ?>" id="change_reason" name="change_reason" maxlength="500" placeholder="Needed when you change GSTIN, PAN, GST treatment or legal name"><?= $view->partial('partials/field-error', ['field' => 'change_reason']) ?></div><?php endif; ?>
                </div>
            </div>
            <div class="card app-card mb-4">
                <div class="card-header">Contact information</div>
                <div class="card-body row g-3">
                    <?php if ($see('contact_person')): ?><div class="col-md-4"><label class="form-label" for="contact_person">Contact person</label><input class="form-control" id="contact_person" name="contact_person" value="<?= e($val('contact_person')) ?>" maxlength="150"<?= $ro('contact_person') ?>></div><?php endif; ?>
                    <?php if ($see('contact_phone')): ?><div class="col-md-4"><label class="form-label" for="mobile">Mobile</label><input class="form-control<?= invalid('mobile') ?>" id="mobile" name="mobile" value="<?= e($val('mobile')) ?>" maxlength="20" inputmode="tel"<?= $ro('contact_phone') ?>><?= $view->partial('partials/field-error', ['field' => 'mobile']) ?></div><?php endif; ?>
                    <?php if ($see('contact_email')): ?><div class="col-md-4"><label class="form-label" for="email">Email</label><input class="form-control<?= invalid('email') ?>" type="email" id="email" name="email" value="<?= e($val('email')) ?>" maxlength="150"<?= $ro('contact_email') ?>><?= $view->partial('partials/field-error', ['field' => 'email']) ?></div><?php endif; ?>
                </div>
            </div>
            <?php if (!$editing && $see('addresses')): ?>
            <div class="card app-card mb-4">
                <div class="card-header">Business address <span class="small text-secondary fw-normal ms-2">optional — more addresses on the supplier page</span></div>
                <div class="card-body row g-3">
                    <div class="col-12"><label class="form-label" for="addr_line1">Address</label><input class="form-control<?= invalid('line1') ?>" id="addr_line1" name="addr_line1" value="<?= e((string) old('addr_line1', '')) ?>" maxlength="200"><?= $view->partial('partials/field-error', ['field' => 'line1']) ?></div>
                    <div class="col-12"><input class="form-control" name="addr_line2" value="<?= e((string) old('addr_line2', '')) ?>" maxlength="200" placeholder="Address line 2" aria-label="Address line 2"></div>
                    <div class="col-md-4"><label class="form-label" for="addr_city">City</label><input class="form-control<?= invalid('city') ?>" id="addr_city" name="addr_city" value="<?= e((string) old('addr_city', '')) ?>" maxlength="100"><?= $view->partial('partials/field-error', ['field' => 'city']) ?></div>
                    <div class="col-md-5"><label class="form-label" for="addr_state_id">State</label><select class="form-select<?= invalid('state_id') ?>" id="addr_state_id" name="addr_state_id"><option value="">Choose…</option><?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>" data-code="<?= e($st['code']) ?>" <?= (string) old('addr_state_id', '') === (string) $st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'state_id']) ?></div>
                    <div class="col-md-3"><label class="form-label" for="addr_pin_code">PIN code</label><input class="form-control<?= invalid('pin_code') ?>" id="addr_pin_code" name="addr_pin_code" value="<?= e((string) old('addr_pin_code', '')) ?>" maxlength="10" inputmode="numeric"><?= $view->partial('partials/field-error', ['field' => 'pin_code']) ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <div class="col-xl-4">
            <div class="card app-card mb-4">
                <div class="card-header">Commercial terms</div>
                <div class="card-body row g-3">
                    <?php if ($see('payment_terms')): ?>
                    <div class="col-12"><label class="form-label" for="payment_terms">Payment terms</label><input class="form-control" id="payment_terms" name="payment_terms" value="<?= e($val('payment_terms')) ?>" maxlength="100" placeholder="e.g. 50% advance, balance on delivery"<?= $ro('payment_terms') ?>></div>
                    <div class="col-6"><label class="form-label" for="credit_period_days">Credit period (days)</label><input class="form-control<?= invalid('credit_period_days') ?>" id="credit_period_days" name="credit_period_days" value="<?= e($val('credit_period_days')) ?>" inputmode="numeric"<?= $ro('payment_terms') ?>><?= $view->partial('partials/field-error', ['field' => 'credit_period_days']) ?></div>
                    <?php endif; ?>
                    <div class="col-6"><label class="form-label" for="default_currency">Currency</label><select class="form-select" id="default_currency" name="default_currency"><?php foreach (array_keys($currencies) as $c): ?><option <?= $val('default_currency', 'INR') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
                    <div class="col-6"><label class="form-label" for="country">Country</label><input class="form-control text-uppercase<?= invalid('country') ?>" id="country" name="country" value="<?= e($val('country', 'IN')) ?>" maxlength="2" title="Two-letter country code"><?= $view->partial('partials/field-error', ['field' => 'country']) ?></div>
                </div>
            </div>
            <?php if ($see('internal_notes')): ?><div class="card app-card mb-4"><div class="card-header">Internal notes</div><div class="card-body"><textarea class="form-control" name="internal_notes" rows="4" maxlength="5000" aria-label="Internal notes"<?= $ro('internal_notes') ?>><?= e($val('internal_notes')) ?></textarea></div></div><?php endif; ?>
            <div class="alert alert-info small"><i data-lucide="shield-check" class="icon-sm"></i> Bank details are added on the supplier page after saving and need approval before use. Never enter internet-banking passwords, PINs, OTPs or card data.</div>
            <div class="d-grid gap-2">
                <?php if ($editing): ?><button class="btn btn-primary" type="submit"><i data-lucide="save" class="icon-sm"></i>Save changes</button><a class="btn btn-light" href="<?= e(route('suppliers.show', ['id' => $s['id']])) ?>">Cancel</a>
                <?php else: ?><button class="btn btn-primary" type="submit" name="action" value="submit"><i data-lucide="send" class="icon-sm"></i>Save &amp; submit</button><button class="btn btn-light" type="submit" name="action" value="draft"><i data-lucide="file-pen" class="icon-sm"></i>Save as draft</button><?php endif; ?>
            </div>
        </div>
    </div>
</form>
