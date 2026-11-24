<?php
/** Per-registration settings used by future modules. @var array<string, mixed> $settings @var list<array<string, mixed>> $states @var list<array<string, mixed>> $types @var array<string, mixed>|null $defaultReg */
$canManage = can('gst_registrations.manage_settings') && $raw['is_archived'] !== 1;
$dis = $canManage ? '' : ' disabled';
$v = static fn (string $k): string => (string) old($k, (string) ($settings[$k] ?? ''));
$stateSelect = static function (string $name, string $id, string $value, array $states, string $dis): string {
    $o = '<select class="form-select" id="' . $id . '" name="' . $name . '"' . $dis . '><option value="">—</option>';
    foreach ($states as $s) {
        $o .= '<option value="' . (int) $s['id'] . '"' . ((int) $value === (int) $s['id'] ? ' selected' : '') . '>' . e($s['code'] . ' · ' . $s['name']) . '</option>';
    }
    return $o . '</select>';
};
?>
<div class="alert alert-info small"><i data-lucide="info" class="icon-sm"></i> These defaults are stored now and will be used by later modules (sales, purchases, invoicing). No GST is calculated and nothing is filed in this release.</div>
<form method="post" action="<?= e(route('gst.settings', ['id' => $raw['id']])) ?>" data-loading>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-12">
            <div class="form-check form-switch"><input type="hidden" name="is_default" value="0">
                <input class="form-check-input" type="checkbox" role="switch" id="s-default" name="is_default" value="1" <?= (int) $v('is_default') === 1 ? 'checked' : '' ?><?= $dis ?>>
                <label class="form-check-label" for="s-default"><b>Default GST registration for business operations</b></label></div>
            <div class="form-text">Only one registration can be the default<?= $defaultReg !== null && (int) $defaultReg['id'] !== (int) $raw['id'] ? '; currently it is ' . e($defaultReg['gstin']) . ' (it will be switched off)' : '' ?>. Only active, approved registrations qualify.</div>
            <?= $view->partial('partials/field-error', ['field' => 'is_default']) ?>
        </div>
        <div class="col-md-6"><label class="form-label" for="s-pos">Default place of supply</label><?= $stateSelect('default_place_of_supply_state_id', 's-pos', $v('default_place_of_supply_state_id'), $states, $dis) ?></div>
        <div class="col-md-6"><label class="form-label" for="s-state">Default state</label><?= $stateSelect('default_state_id', 's-state', $v('default_state_id'), $states, $dis) ?></div>
        <div class="col-md-6"><label class="form-label" for="s-type">Applicable registration type</label>
            <select class="form-select" id="s-type" name="applicable_registration_type_id"<?= $dis ?>><option value="">—</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $v('applicable_registration_type_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label" for="s-from">Effective from</label><input class="form-control" type="date" id="s-from" name="effective_from" value="<?= e($v('effective_from')) ?>"<?= $dis ?>><?= $view->partial('partials/field-error', ['field' => 'effective_from']) ?></div>
        <div class="col-md-3"><label class="form-label" for="s-to">Effective to</label><input class="form-control" type="date" id="s-to" name="effective_to" value="<?= e($v('effective_to')) ?>"<?= $dis ?>><?= $view->partial('partials/field-error', ['field' => 'effective_to']) ?></div>
        <div class="col-12"><div class="card-section-title mb-2">Default tax-related configuration</div>
            <?php foreach (['prices_include_tax' => 'Prices are entered inclusive of GST by default', 'e_invoicing_applicable' => 'E-invoicing applies to this registration', 'e_way_bill_applicable' => 'E-way bills apply to this registration'] as $k => $l): ?>
                <div class="form-check"><input type="hidden" name="<?= e($k) ?>" value="0"><input class="form-check-input" type="checkbox" id="s-<?= e($k) ?>" name="<?= e($k) ?>" value="1" <?= (int) $v($k) === 1 ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label" for="s-<?= e($k) ?>"><?= e($l) ?></label></div>
            <?php endforeach; ?></div>
        <div class="col-12"><label class="form-label" for="s-notes">Internal notes</label><textarea class="form-control" id="s-notes" name="notes" rows="2" maxlength="500"<?= $dis ?>><?= e($v('notes')) ?></textarea></div>
    </div>
    <div class="d-flex align-items-center gap-3 mt-3">
        <?php if ($canManage): ?><button class="btn btn-primary" type="submit">Save settings</button><?php endif; ?>
        <?php if (!empty($settings['updated_by_name'])): ?><span class="small text-secondary">Last changed <?= e(format_datetime($settings['updated_at'] ?? null)) ?> by <?= e($settings['updated_by_name']) ?></span><?php endif; ?>
    </div>
</form>
