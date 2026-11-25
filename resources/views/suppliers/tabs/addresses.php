<?php
/** @var array<string, mixed> $s @var list<array<string, mixed>> $addresses @var list<array<string, mixed>> $states @var array<string, string> $addressTypes @var int $edit @var bool $showArchived @var array<string, mixed> $fields */
use App\Services\Supplier\SupplierDetailService;

$access = $fields['addresses'] ?? null;
if ($access && !$access->canView()) {
    echo '<p class="text-secondary">Addresses are hidden by your field permissions.</p>';
    return;
}
$mask = $access && !$access->showsRealValue();
$canManage = $s['status'] !== 'archived' && can('suppliers.manage_addresses') && (!$access || $access->canEdit());
$editing = null;
foreach ($addresses as $a) {
    if ((int) $a['id'] === $edit) {
        $editing = $a;
    }
}
$v = static fn (string $k, mixed $d = ''): string => (string) old($k, $editing[$k] ?? $d);
?>
<div class="d-flex justify-content-between align-items-center mb-2"><p class="text-secondary small mb-0">Business, billing, shipping and other addresses. One default per type.</p>
    <a class="small" href="<?= e(route('suppliers.show', ['id' => $s['id'], 'tab' => 'addresses', 'archived' => $showArchived ? 0 : 1])) ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a></div>
<?php if ($canManage): ?>
<details class="card app-card mb-4 bg-fill" <?= $addresses === [] || $editing ? 'open' : '' ?>>
    <summary class="card-header"><?= $editing ? 'Edit address' : 'Add address' ?></summary>
    <form method="post" action="<?= e($editing ? route('suppliers.addresses.update', ['id' => $s['id'], 'address' => $editing['id']]) : route('suppliers.addresses.store', ['id' => $s['id']])) ?>" class="card-body row g-2 align-items-end" data-loading><?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label small" for="ad-type">Type</label><select class="form-select" id="ad-type" name="address_type"><?php foreach ($addressTypes as $k => $l): ?><option value="<?= e($k) ?>" <?= $v('address_type', 'business') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small" for="ad-label">Label</label><input class="form-control" id="ad-label" name="label" value="<?= e($v('label')) ?>" maxlength="100" placeholder="e.g. Factory, Warehouse 2"></div>
        <div class="col-md-6"><label class="form-label small" for="ad-l1">Address <span class="text-danger">*</span></label><input class="form-control<?= invalid('line1') ?>" id="ad-l1" name="line1" value="<?= e($v('line1')) ?>" maxlength="200" required><?= $view->partial('partials/field-error', ['field' => 'line1']) ?></div>
        <div class="col-md-6"><label class="form-label small" for="ad-l2">Address line 2</label><input class="form-control" id="ad-l2" name="line2" value="<?= e($v('line2')) ?>" maxlength="200"></div>
        <div class="col-md-3"><label class="form-label small" for="ad-city">City <span class="text-danger">*</span></label><input class="form-control<?= invalid('city') ?>" id="ad-city" name="city" value="<?= e($v('city')) ?>" maxlength="100" required></div>
        <div class="col-md-3"><label class="form-label small" for="ad-country">Country</label><input class="form-control text-uppercase<?= invalid('country') ?>" id="ad-country" name="country" value="<?= e($v('country', $s['country'])) ?>" maxlength="2"></div>
        <div class="col-md-4"><label class="form-label small" for="ad-state">State (India)</label><select class="form-select<?= invalid('state_id') ?>" id="ad-state" name="state_id"><option value="">—</option><?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>" <?= (int) $v('state_id') === (int) $st['id'] ? 'selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'state_id']) ?></div>
        <div class="col-md-3"><label class="form-label small" for="ad-region">Region (outside India)</label><input class="form-control" id="ad-region" name="state_name" value="<?= e($v('state_name')) ?>" maxlength="100"></div>
        <div class="col-md-2"><label class="form-label small" for="ad-pin">PIN / postal code</label><input class="form-control<?= invalid('pin_code') ?>" id="ad-pin" name="pin_code" value="<?= e($v('pin_code')) ?>" maxlength="10"><?= $view->partial('partials/field-error', ['field' => 'pin_code']) ?></div>
        <div class="col-md-3 d-flex align-items-center"><div class="form-check"><input class="form-check-input" type="checkbox" id="ad-def" name="is_default" value="1" <?= (int) ($editing['is_default'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label" for="ad-def">Default for its type</label></div></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><?= $editing ? 'Save' : 'Add' ?></button></div>
    </form>
</details>
<?php endif; ?>
<?php if ($addresses === []): ?><?= $view->partial('partials/empty', ['icon' => 'map-pin', 'text' => 'No addresses yet.']) ?><?php else: ?>
<div class="row g-3">
    <?php foreach ($addresses as $a): ?>
    <div class="col-md-6"><div class="card app-card h-100 <?= $a['status'] !== 'active' ? 'opacity-75' : '' ?>"><div class="card-body">
        <div class="d-flex justify-content-between"><b><?= e($addressTypes[$a['address_type']]) ?><?= $a['label'] ? ' · ' . e($a['label']) : '' ?></b>
            <span><?= (int) $a['is_default'] ? '<span class="badge badge-soft-primary">Default</span>' : '' ?><?= $a['status'] !== 'active' ? '<span class="badge badge-soft-dark">Archived</span>' : '' ?></span></div>
        <p class="mb-2 mt-1"><?= e($mask ? '••••••' : SupplierDetailService::format($a)) ?></p>
        <?php if ($canManage && $a['status'] === 'active'): ?><div class="d-flex gap-2"><a class="btn btn-sm btn-light" href="<?= e(route('suppliers.show', ['id' => $s['id'], 'tab' => 'addresses', 'edit' => $a['id']])) ?>">Edit</a>
            <form method="post" action="<?= e(route('suppliers.addresses.archive', ['id' => $s['id'], 'address' => $a['id']])) ?>" data-confirm="Archive this address?"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit">Archive</button></form></div><?php endif; ?>
    </div></div></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
