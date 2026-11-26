<?php
/** Add / edit a warehouse. @var array<string, mixed>|null $w @var list<array<string, mixed>> $types @var list<array<string, mixed>> $states @var array<string, mixed> $fields */
$editing = $w !== null;
$val = static fn (string $k, mixed $default = ''): string => (string) old($k, $w[$k] ?? $default);
$req = '<span class="text-danger">*</span>';
$see = static fn (string $f): bool => !isset($fields[$f]) || $fields[$f]->canView();
$ro = static fn (string $f): string => !isset($fields[$f]) || $fields[$f]->canEdit() ? '' : ' disabled';
$err = static fn (string $f): string => $view->partial('partials/field-error', ['field' => $f]);
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit warehouse' : 'Add warehouse' ?></h1>
    <p><?= $editing ? e($w['code'] . ' · ' . $w['name']) : 'A place where stock is kept: own warehouse, store, third-party warehouse, fulfilment centre or a virtual location for returns, damaged goods or transit.' ?></p></div></div>
<form method="post" action="<?= e($editing ? route('inventory.warehouses.update', ['id' => $w['id']]) : route('inventory.warehouses.store')) ?>" data-loading><?= csrf_field() ?>
<div class="row g-4"><div class="col-xl-8">
    <div class="card app-card mb-4"><div class="card-header">Warehouse</div><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label" for="name">Name <?= $req ?></label><input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="150" required><?= $err('name') ?></div>
        <div class="col-md-3"><label class="form-label" for="code">Code</label><input class="form-control text-uppercase text-mono<?= invalid('code') ?>" id="code" name="code" value="<?= e($val('code')) ?>" maxlength="30" placeholder="Automatic"><?= $err('code') ?></div>
        <div class="col-md-3"><label class="form-label" for="warehouse_type_id">Type <?= $req ?></label><select class="form-select<?= invalid('warehouse_type_id') ?>" id="warehouse_type_id" name="warehouse_type_id" required><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>" <?= (int) $val('warehouse_type_id') === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select><?= $err('warehouse_type_id') ?></div>
        <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="uses_locations" name="uses_locations" value="1" <?= $val('uses_locations') === '1' ? 'checked' : '' ?>>
            <label class="form-check-label" for="uses_locations">Storage locations are required for every receipt (zones, racks, shelves, bins)</label></div><?= $err('uses_locations') ?></div>
    </div></div>
    <div class="card app-card mb-4"><div class="card-header">Address</div><div class="card-body row g-3">
        <div class="col-12"><label class="form-label" for="line1">Address</label><input class="form-control<?= invalid('line1') ?>" id="line1" name="line1" value="<?= e($val('line1')) ?>" maxlength="200"><?= $err('line1') ?></div>
        <div class="col-12"><input class="form-control" name="line2" value="<?= e($val('line2')) ?>" maxlength="200" placeholder="Address line 2" aria-label="Address line 2"></div>
        <div class="col-md-4"><label class="form-label" for="city">City</label><input class="form-control<?= invalid('city') ?>" id="city" name="city" value="<?= e($val('city')) ?>" maxlength="100"><?= $err('city') ?></div>
        <div class="col-md-4"><label class="form-label" for="state_id">State</label><select class="form-select<?= invalid('state_id') ?>" id="state_id" name="state_id"><option value="">Choose…</option><?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>" <?= $val('state_id') === (string) $st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?></select><?= $err('state_id') ?></div>
        <div class="col-md-2"><label class="form-label" for="pin_code">PIN code</label><input class="form-control<?= invalid('pin_code') ?>" id="pin_code" name="pin_code" value="<?= e($val('pin_code')) ?>" maxlength="10" inputmode="numeric"><?= $err('pin_code') ?></div>
        <div class="col-md-2"><label class="form-label" for="country">Country</label><input class="form-control text-uppercase<?= invalid('country') ?>" id="country" name="country" value="<?= e($val('country', 'IN')) ?>" maxlength="2"><?= $err('country') ?></div>
        <div class="col-md-6"><label class="form-label" for="state_name">State / region (outside India)</label><input class="form-control" id="state_name" name="state_name" value="<?= e($val('state_name')) ?>" maxlength="100"></div>
    </div></div>
    <?php if ($see('warehouse_contact')): ?>
    <div class="card app-card mb-4"><div class="card-header">Contact</div><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label" for="contact_person">Contact person</label><input class="form-control" id="contact_person" name="contact_person" value="<?= e($val('contact_person')) ?>" maxlength="150"<?= $ro('warehouse_contact') ?>></div>
        <div class="col-md-4"><label class="form-label" for="mobile">Mobile</label><input class="form-control<?= invalid('mobile') ?>" id="mobile" name="mobile" value="<?= e($val('mobile')) ?>" maxlength="20" inputmode="tel"<?= $ro('warehouse_contact') ?>><?= $err('mobile') ?></div>
        <div class="col-md-4"><label class="form-label" for="email">Email</label><input class="form-control<?= invalid('email') ?>" type="email" id="email" name="email" value="<?= e($val('email')) ?>" maxlength="150"<?= $ro('warehouse_contact') ?>><?= $err('email') ?></div>
    </div></div>
    <?php endif; ?>
    <?php if ($see('internal_notes')): ?>
    <div class="card app-card mb-4"><div class="card-header">Internal notes</div><div class="card-body"><textarea class="form-control" name="internal_notes" rows="3" maxlength="5000" aria-label="Internal notes"<?= $ro('internal_notes') ?>><?= e($val('internal_notes')) ?></textarea></div></div>
    <?php endif; ?>
</div>
<div class="col-xl-4"><div class="card app-card"><div class="card-body small text-secondary">
    <p>GST registrations and business activities are linked on the warehouse page after saving. Users are assigned there too — users with “assigned warehouses” visibility then see only those.</p>
    <p class="mb-0">A warehouse that has ever held stock is never deleted: archive it once it is empty and its history stays available.</p>
</div></div></div>
</div>
<div class="form-actions"><a class="btn btn-light" href="<?= e($editing ? route('inventory.warehouses.show', ['id' => $w['id']]) : route('inventory.warehouses.index')) ?>">Cancel</a><button class="btn btn-primary" type="submit"><i data-lucide="save" class="icon-sm"></i><?= $editing ? 'Save warehouse' : 'Add warehouse' ?></button></div>
</form>
