<?php
/**
 * Add / edit a GST registration in sections: A basic details, B principal place of business, C additional information.
 * @var array<string, mixed>|null $reg @var array<string, mixed>|null $principal @var list<array<string, mixed>> $states @var list<array<string, mixed>> $types
 * @var array<string, mixed> $fieldAccess @var mixed $addressAccess @var array<string, mixed>|null $pending
 */
use App\Services\Gst\GstRegistrationService;

$editing = $reg !== null;
$val = static fn (string $k, mixed $default = ''): string => (string) old($k, $reg[$k] ?? $default);
$addr = static fn (string $k, string $col, mixed $default = ''): string => (string) old($k, $principal[$col] ?? $default);
$canEdit = static fn (string $k): bool => !isset($fieldAccess[$k]) || $fieldAccess[$k]->canEdit();
$canSee = static fn (string $k): bool => !isset($fieldAccess[$k]) || $fieldAccess[$k]->canView();
$addrEditable = $addressAccess === null || $addressAccess->canEdit();
$ro = static fn (string $k): string => $canEdit($k) ? '' : ' disabled';
$keyHint = $editing ? ' <span class="badge badge-soft-info" title="Changing this is a key-detail change and may need approval">Key detail</span>' : '';
?>
<div class="page-head">
    <div>
        <h1><?= $editing ? 'Edit GST registration' : 'Add GST registration' ?></h1>
        <p><?= $editing ? e($reg['gstin'] ?? '') . ' · ' . e($reg['legal_name'] ?? '') : 'Enter the details exactly as they appear on the registration certificate (REG-06).' ?></p>
    </div>
</div>

<?php if ($editing && $pending !== null): ?>
    <div class="alert alert-warning small"><i data-lucide="clock" class="icon-sm"></i> A <?= e($pending['change_type']) ?> request is waiting for approval. Key details cannot be changed again until it is decided.</div>
<?php endif; ?>
<div class="alert alert-info small"><i data-lucide="shield-check" class="icon-sm"></i> Never enter GST portal passwords, OTPs or other login credentials here. GSTIN checks confirm the format only; they do not verify the registration with the GST portal.</div>

<form method="post" action="<?= e($editing ? route('gst.update', ['id' => $reg['id']]) : route('gst.store')) ?>" data-loading id="gst-form" data-check-url="<?= e(route('gst.check')) ?>" data-except="<?= $editing ? (int) $reg['id'] : 0 ?>">
    <?= csrf_field() ?>
    <div class="card app-card mb-4">
        <div class="card-header">A. Basic registration details</div>
        <div class="card-body row g-3">
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="gstin">GSTIN <span class="text-danger">*</span><?= $keyHint ?></label>
                <input class="form-control text-mono text-uppercase<?= invalid('gstin') ?>" id="gstin" name="gstin" value="<?= e($val('gstin')) ?>" maxlength="20" required autocomplete="off" spellcheck="false" placeholder="15 characters, e.g. 24ABCPM1234K1Z5" data-gstin<?= $ro('gstin') ?>>
                <?= $view->partial('partials/field-error', ['field' => 'gstin']) ?>
                <div class="form-text" id="gstin-feedback" aria-live="polite">State, PAN and the check character are verified as you type.</div>
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="registration_type_id">Registration type <span class="text-danger">*</span><?= $keyHint ?></label>
                <select class="form-select<?= invalid('registration_type_id') ?>" id="registration_type_id" name="registration_type_id" required>
                    <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" data-validity="<?= (int) $t['has_validity_period'] ?>" <?= (int) $val('registration_type_id', $types[0]['id'] ?? 0) === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?>
                </select><?= $view->partial('partials/field-error', ['field' => 'registration_type_id']) ?>
            </div>
            <div class="col-md-6 col-xl-4">
                <label class="form-label" for="gst-state">State / state code</label>
                <input class="form-control" id="gst-state" value="<?= $editing ? e(($reg['state_code'] ?? '') . ' · ' . ($reg['state_name'] ?? '')) : '' ?>" readonly tabindex="-1" placeholder="Filled in from the GSTIN">
                <div class="form-text">Taken from the first two GSTIN characters (GST state master).</div>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="legal_name">Legal business name <span class="text-danger">*</span><?= $keyHint ?></label>
                <input class="form-control<?= invalid('legal_name') ?>" id="legal_name" name="legal_name" value="<?= e($val('legal_name')) ?>" maxlength="200" required<?= $ro('legal_name') ?>><?= $view->partial('partials/field-error', ['field' => 'legal_name']) ?>
            </div>
            <?php if ($canSee('pan')): ?>
            <div class="col-md-4">
                <label class="form-label" for="pan">PAN<?= $keyHint ?></label>
                <input class="form-control text-mono text-uppercase<?= invalid('pan') ?>" id="pan" name="pan" value="<?= e($val('pan')) ?>" maxlength="12" placeholder="From the GSTIN if empty"<?= $ro('pan') ?>><?= $view->partial('partials/field-error', ['field' => 'pan']) ?>
            </div>
            <?php endif; ?>
            <?php if ($canSee('trade_name')): ?>
            <div class="col-md-8"><label class="form-label" for="trade_name">Trade name</label>
                <input class="form-control<?= invalid('trade_name') ?>" id="trade_name" name="trade_name" value="<?= e($val('trade_name')) ?>" maxlength="200"<?= $ro('trade_name') ?>><?= $view->partial('partials/field-error', ['field' => 'trade_name']) ?></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label" for="business_constitution">Business constitution</label>
                <select class="form-select" id="business_constitution" name="business_constitution"><option value="">—</option>
                    <?php foreach (GstRegistrationService::CONSTITUTIONS as $c): ?><option <?= $val('business_constitution') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
            <?php if (!$editing): ?>
            <div class="col-md-4"><label class="form-label" for="status">Registration status <span class="text-danger">*</span></label>
                <select class="form-select<?= invalid('status') ?>" id="status" name="status" required>
                    <?php foreach (GstRegistrationService::INITIAL_STATUSES as $s): ?><option value="<?= e($s) ?>" <?= $val('status', 'pending_verification') === $s ? 'selected' : '' ?>><?= e(GstRegistrationService::STATUSES[$s]) ?></option><?php endforeach; ?>
                </select><div class="form-text">Use “Pending verification” until you have checked the certificate. Suspension and cancellation are separate actions.</div></div>
            <?php endif; ?>
            <div class="col-md-4"><label class="form-label" for="registration_date">Registration date<?= $keyHint ?></label>
                <input class="form-control<?= invalid('registration_date') ?>" type="date" id="registration_date" name="registration_date" value="<?= e($val('registration_date')) ?>" max="<?= e(\App\Support\BusinessDate::today()) ?>"><?= $view->partial('partials/field-error', ['field' => 'registration_date']) ?></div>
            <div class="col-md-4"><label class="form-label" for="effective_from">Effective from<?= $keyHint ?></label>
                <input class="form-control<?= invalid('effective_from') ?>" type="date" id="effective_from" name="effective_from" value="<?= e($val('effective_from')) ?>"><?= $view->partial('partials/field-error', ['field' => 'effective_from']) ?>
                <div class="form-text">May be earlier than the registration date.</div></div>
            <div class="col-md-4" data-validity-field><label class="form-label" for="validity_end_date">Validity end date</label>
                <input class="form-control<?= invalid('validity_end_date') ?>" type="date" id="validity_end_date" name="validity_end_date" value="<?= e($val('validity_end_date')) ?>"><?= $view->partial('partials/field-error', ['field' => 'validity_end_date']) ?>
                <div class="form-text">Only for casual and non-resident registrations.</div></div>
            <?php if ($editing && $reg['status'] === 'cancelled'): ?>
            <div class="col-md-4"><label class="form-label">Cancellation date</label><input class="form-control" value="<?= e(format_date($reg['cancellation_date'])) ?>" readonly></div>
            <?php endif; ?>
            <?php foreach (['contact_person' => ['Primary contact person', 'text', 150], 'contact_phone' => ['Contact number', 'tel', 20], 'contact_email' => ['Email address', 'email', 191]] as $f => [$label, $type, $max]): if (!$canSee($f)) { continue; } ?>
                <div class="col-md-4"><label class="form-label" for="<?= e($f) ?>"><?= e($label) ?></label>
                    <input class="form-control<?= invalid($f) ?>" type="<?= e($type) ?>" id="<?= e($f) ?>" name="<?= e($f) ?>" value="<?= e($val($f)) ?>" maxlength="<?= (int) $max ?>"<?= $ro($f) ?>><?= $view->partial('partials/field-error', ['field' => $f]) ?></div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card app-card mb-4">
        <div class="card-header">B. Principal place of business<span class="small text-secondary fw-normal ms-auto">Additional locations are added on the registration's Locations tab</span></div>
        <?php if (!$addrEditable && $editing): ?>
            <div class="card-body pt-0 text-secondary small">You may not change business addresses. They stay as they are.</div>
        <?php else: ?>
        <div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label" for="addr_line1">Address line 1 <span class="text-danger">*</span></label><input class="form-control<?= invalid('addr_line1') ?>" id="addr_line1" name="addr_line1" value="<?= e($addr('addr_line1', 'address_line1')) ?>" maxlength="200" required><?= $view->partial('partials/field-error', ['field' => 'addr_line1']) ?></div>
            <div class="col-md-6"><label class="form-label" for="addr_line2">Address line 2</label><input class="form-control" id="addr_line2" name="addr_line2" value="<?= e($addr('addr_line2', 'address_line2')) ?>" maxlength="200"></div>
            <div class="col-md-4"><label class="form-label" for="addr_city">City <span class="text-danger">*</span></label><input class="form-control<?= invalid('addr_city') ?>" id="addr_city" name="addr_city" value="<?= e($addr('addr_city', 'city')) ?>" maxlength="100" required><?= $view->partial('partials/field-error', ['field' => 'addr_city']) ?></div>
            <div class="col-md-4"><label class="form-label" for="addr_district">District</label><input class="form-control" id="addr_district" name="addr_district" value="<?= e($addr('addr_district', 'district')) ?>" maxlength="100"></div>
            <div class="col-md-4"><label class="form-label" for="addr_state_id">State <span class="text-danger">*</span></label>
                <select class="form-select<?= invalid('addr_state_id') ?>" id="addr_state_id" name="addr_state_id" required data-follow-gstin><option value="">Choose…</option>
                    <?php foreach ($states as $s): ?><option value="<?= (int) $s['id'] ?>" <?= (int) $addr('addr_state_id', 'state_id', $reg['state_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['code'] . ' · ' . $s['name']) ?></option><?php endforeach; ?>
                </select><?= $view->partial('partials/field-error', ['field' => 'addr_state_id']) ?></div>
            <div class="col-md-4"><label class="form-label" for="addr_pin_code">PIN code <span class="text-danger">*</span></label><input class="form-control<?= invalid('addr_pin_code') ?>" id="addr_pin_code" name="addr_pin_code" value="<?= e($addr('addr_pin_code', 'pin_code')) ?>" inputmode="numeric" maxlength="6" required><?= $view->partial('partials/field-error', ['field' => 'addr_pin_code']) ?></div>
            <div class="col-md-4"><label class="form-label" for="addr_country">Country</label><input class="form-control" id="addr_country" name="addr_country" value="<?= e($addr('addr_country', 'country', 'India')) ?>" maxlength="80"></div>
            <div class="col-md-4"><label class="form-label" for="addr_contact_name">Location contact</label><input class="form-control" id="addr_contact_name" name="addr_contact_name" value="<?= e($addr('addr_contact_name', 'contact_name')) ?>" maxlength="150"></div>
            <div class="col-md-4"><label class="form-label" for="addr_contact_phone">Location contact number</label><input class="form-control<?= invalid('addr_contact_phone') ?>" type="tel" id="addr_contact_phone" name="addr_contact_phone" value="<?= e($addr('addr_contact_phone', 'contact_phone')) ?>" maxlength="20"><?= $view->partial('partials/field-error', ['field' => 'addr_contact_phone']) ?></div>
            <div class="col-md-4"><label class="form-label" for="addr_contact_email">Location email</label><input class="form-control<?= invalid('addr_contact_email') ?>" type="email" id="addr_contact_email" name="addr_contact_email" value="<?= e($addr('addr_contact_email', 'contact_email')) ?>" maxlength="191"><?= $view->partial('partials/field-error', ['field' => 'addr_contact_email']) ?></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="card app-card mb-4">
        <div class="card-header">C. Additional business information</div>
        <div class="card-body row g-3">
            <div class="col-md-6"><label class="form-label" for="nature_of_business">Nature of business</label><input class="form-control" id="nature_of_business" name="nature_of_business" value="<?= e($val('nature_of_business')) ?>" maxlength="500" placeholder="e.g. Retail business, Wholesale business, Supplier of services"></div>
            <div class="col-md-3"><label class="form-label" for="taxpayer_category">Taxpayer category</label>
                <select class="form-select" id="taxpayer_category" name="taxpayer_category"><option value="">—</option><?php foreach (GstRegistrationService::TAXPAYER_CATEGORIES as $c): ?><option <?= $val('taxpayer_category') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
            <?php if ($canSee('portal_reference')): ?>
            <div class="col-md-3"><label class="form-label" for="portal_reference">GST portal reference</label><input class="form-control<?= invalid('portal_reference') ?>" id="portal_reference" name="portal_reference" value="<?= e($val('portal_reference')) ?>" maxlength="120" placeholder="ARN / application ref."<?= $ro('portal_reference') ?>>
                <div class="form-text">Reference numbers only — never passwords.</div></div>
            <?php endif; ?>
            <?php if ($canSee('internal_notes')): ?>
            <div class="col-12"><label class="form-label" for="internal_notes">Internal notes</label><textarea class="form-control" id="internal_notes" name="internal_notes" rows="3" maxlength="5000"<?= $ro('internal_notes') ?>><?= e($val('internal_notes')) ?></textarea></div>
            <?php endif; ?>
            <p class="small text-secondary mb-0">Business activities (e-commerce, wholesale…), partner associations, documents and settings are managed on the registration's tabs after saving.</p>
        </div>
    </div>

    <div class="form-actions-bar">
        <?php if ($editing): ?><input class="form-control me-auto change-note" name="change_reason" maxlength="255" placeholder="Reason for key-detail changes (shown to approvers)" aria-label="Reason for change"><?php endif; ?>
        <a class="btn btn-light" href="<?= e($editing ? route('gst.show', ['id' => $reg['id']]) : route('gst.index')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit"><?= $editing ? 'Save changes' : 'Add registration' ?></button>
    </div>
</form>
