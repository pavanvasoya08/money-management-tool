<?php
/** Principal and additional places of business. @var list<array<string, mixed>> $locations @var list<array<string, mixed>> $states @var mixed $addressAccess */
$canManage = can('gst_registrations.manage_locations') && $raw['is_archived'] !== 1 && $raw['status'] !== 'cancelled' && $addressAccess->canEdit();
$fields = static function (array $l, array $states, string $p): string {
    $v = static fn (string $k): string => e((string) ($l[$k] ?? ''));
    $opts = '';
    foreach ($states as $s) {
        $opts .= '<option value="' . (int) $s['id'] . '"' . ((int) ($l['state_id'] ?? 0) === (int) $s['id'] ? ' selected' : '') . '>' . e($s['code'] . ' · ' . $s['name']) . '</option>';
    }
    return '<div class="row g-2">'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . 'label">Label</label><input class="form-control form-control-sm" id="' . $p . 'label" name="label" value="' . $v('label') . '" maxlength="100" placeholder="e.g. Warehouse, Branch"></div>'
        . '<div class="col-md-8"><label class="form-label small" for="' . $p . 'l1">Address line 1 *</label><input class="form-control form-control-sm" id="' . $p . 'l1" name="address_line1" value="' . $v('address_line1') . '" maxlength="200" required></div>'
        . '<div class="col-md-6"><label class="form-label small" for="' . $p . 'l2">Address line 2</label><input class="form-control form-control-sm" id="' . $p . 'l2" name="address_line2" value="' . $v('address_line2') . '" maxlength="200"></div>'
        . '<div class="col-md-3"><label class="form-label small" for="' . $p . 'city">City *</label><input class="form-control form-control-sm" id="' . $p . 'city" name="city" value="' . $v('city') . '" maxlength="100" required></div>'
        . '<div class="col-md-3"><label class="form-label small" for="' . $p . 'dist">District</label><input class="form-control form-control-sm" id="' . $p . 'dist" name="district" value="' . $v('district') . '" maxlength="100"></div>'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . 'state">State *</label><select class="form-select form-select-sm" id="' . $p . 'state" name="state_id" required><option value="">Choose…</option>' . $opts . '</select></div>'
        . '<div class="col-md-2"><label class="form-label small" for="' . $p . 'pin">PIN code *</label><input class="form-control form-control-sm" id="' . $p . 'pin" name="pin_code" value="' . $v('pin_code') . '" maxlength="10" required></div>'
        . '<div class="col-md-2"><label class="form-label small" for="' . $p . 'country">Country</label><input class="form-control form-control-sm" id="' . $p . 'country" name="country" value="' . ($v('country') ?: 'India') . '" maxlength="80"></div>'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . 'cn">Contact</label><input class="form-control form-control-sm" id="' . $p . 'cn" name="contact_name" value="' . $v('contact_name') . '" maxlength="150"></div>'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . 'cp">Contact number</label><input class="form-control form-control-sm" type="tel" id="' . $p . 'cp" name="contact_phone" value="' . $v('contact_phone') . '" maxlength="20"></div>'
        . '<div class="col-md-4"><label class="form-label small" for="' . $p . 'ce">Email</label><input class="form-control form-control-sm" type="email" id="' . $p . 'ce" name="contact_email" value="' . $v('contact_email') . '" maxlength="191"></div>'
        . '</div>';
};
?>
<p class="text-secondary small">The principal place of business and any number of additional places of business. Exactly one active location is the principal. Locations are deactivated, never deleted.</p>
<?php if ($canManage): ?>
<details class="card app-card mb-4 bg-fill add-panel" <?= errors() !== [] ? 'open' : '' ?>>
    <summary class="card-body py-3 fw-semibold"><i data-lucide="plus" class="icon-sm"></i> Add a business location</summary>
    <form class="card-body pt-0" method="post" action="<?= e(route('gst.locations.add', ['id' => $raw['id']])) ?>" data-loading><?= csrf_field() ?>
        <?= $fields(['country' => 'India', 'state_id' => (int) ($reg['state_id'] ?? 0)], $states, 'new-') ?>
        <?php foreach (['address_line1', 'city', 'state_id', 'pin_code', 'contact_phone', 'contact_email'] as $f): ?><?= $view->partial('partials/field-error', ['field' => $f]) ?><?php endforeach; ?>
        <button class="btn btn-primary mt-3" type="submit">Add location</button>
    </form>
</details>
<?php endif; ?>
<?php if ($locations === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'map-pin', 'text' => 'No locations recorded.']) ?>
<?php endif; ?>
<div class="row g-3">
<?php foreach ($locations as $l): ?>
    <div class="col-lg-6">
        <div class="card-tile h-100 <?= $l['status'] !== 'active' ? 'opacity-75' : '' ?>">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                <div class="fw-semibold"><?= e($l['label'] ?: ((int) $l['is_principal'] ? 'Principal place of business' : 'Additional place of business')) ?></div>
                <div class="text-nowrap"><?php if ((int) $l['is_principal']): ?><span class="badge rounded-pill badge-soft-primary">Principal</span><?php endif; ?>
                    <span class="badge rounded-pill badge-soft-<?= $l['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(ucfirst($l['status'])) ?></span></div>
            </div>
            <?php if (!empty($l['hidden'])): ?>
                <p class="text-secondary small mb-0">Address hidden by your field permissions · <?= e($l['state_code']) ?></p>
            <?php else: ?>
                <address class="small mb-2"><?= e($l['address_line1']) ?><?= $l['address_line2'] ? '<br>' . e($l['address_line2']) : '' ?><br><?= e($l['city']) ?><?= $l['district'] ? ', ' . e($l['district']) : '' ?> · <?= e($l['state_code'] . ' ' . $l['state_name']) ?> <?= e($l['pin_code']) ?><br><?= e($l['country']) ?></address>
                <?php if ($l['contact_name'] || $l['contact_phone'] || $l['contact_email']): ?><div class="small text-secondary"><?= e(implode(' · ', array_filter([$l['contact_name'], $l['contact_phone'], $l['contact_email']]))) ?></div><?php endif; ?>
            <?php endif; ?>
            <?php if ($canManage && empty($l['masked']) && empty($l['hidden'])): ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <details class="inline-edit flex-grow-1"><summary class="btn btn-sm btn-light">Edit</summary>
                    <form method="post" action="<?= e(route('gst.locations.update', ['id' => $raw['id'], 'location' => $l['id']])) ?>" class="inline-edit-body inline-edit-wide" data-loading><?= csrf_field() ?>
                        <?= $fields($l, $states, 'e' . (int) $l['id'] . '-') ?><button class="btn btn-sm btn-primary mt-2" type="submit">Save location</button></form></details>
                <?php if ($l['status'] === 'active' && !(int) $l['is_principal']): ?>
                    <form method="post" action="<?= e(route('gst.locations.principal', ['id' => $raw['id'], 'location' => $l['id']])) ?>" data-confirm="Make this the principal place of business? The current principal becomes an additional place." data-confirm-button="Make principal"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit">Make principal</button></form>
                <?php endif; ?>
                <?php if (!(int) $l['is_principal']): ?>
                    <form method="post" action="<?= e(route('gst.locations.status', ['id' => $raw['id'], 'location' => $l['id']])) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $l['status'] === 'active' ? 'inactive' : 'active' ?>"><button class="btn btn-sm btn-light" type="submit"><?= $l['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>
