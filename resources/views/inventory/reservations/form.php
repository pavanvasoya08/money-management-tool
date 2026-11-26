<?php
/** @var array<string, mixed>|null $w @var list<array<string, mixed>> $warehouses @var list<array<string, mixed>> $stock */
use App\Services\Inventory\ReservationService;
?>
<div class="page-head"><div><h1>Reserve stock</h1><p>Only free stock can be reserved (sellable, not expired or on hold, and not already reserved). The reservation ends when it is fulfilled by an outward, released, cancelled or expires.</p></div></div>
<form class="card app-card mb-4" method="get" action="<?= e(route('inventory.reservations.create')) ?>"><div class="card-body row g-2 align-items-end">
    <div class="col-md-6"><label class="form-label small" for="pick-wh">Warehouse <span class="text-danger">*</span></label><select class="form-select" id="pick-wh" name="warehouse" data-autosubmit><option value="">Choose the warehouse…</option><?php foreach ($warehouses as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $w !== null && (int) $w['id'] === (int) $o['id'] ? 'selected' : '' ?>><?= e($o['code'] . ' · ' . $o['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><noscript><button class="btn btn-light w-100" type="submit">Continue</button></noscript></div>
</div></form>
<?php if ($w !== null): ?>
<form method="post" action="<?= e(route('inventory.reservations.store')) ?>" data-loading><?= csrf_field() ?><input type="hidden" name="warehouse_id" value="<?= (int) $w['id'] ?>">
    <div class="card app-card mb-4"><div class="card-header"><?= e($w['code'] . ' · ' . $w['name']) ?></div><div class="card-body row g-3">
        <div class="col-md-4"><label class="form-label" for="reference_type">Reserved for <span class="text-danger">*</span></label><select class="form-select<?= invalid('reference_type') ?>" id="reference_type" name="reference_type"><?php foreach (ReservationService::REFERENCE_TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) old('reference_type', 'internal') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $view->partial('partials/field-error', ['field' => 'reference_type']) ?></div>
        <div class="col-md-4"><label class="form-label" for="reference_no">Reference <span class="text-danger">*</span></label><input class="form-control<?= invalid('reference_no') ?>" id="reference_no" name="reference_no" value="<?= e((string) old('reference_no', '')) ?>" maxlength="100" required placeholder="e.g. order or request number"><?= $view->partial('partials/field-error', ['field' => 'reference_no']) ?></div>
        <div class="col-6 col-md-2"><label class="form-label" for="reservation_date">Date</label><input class="form-control<?= invalid('reservation_date') ?>" type="date" id="reservation_date" name="reservation_date" value="<?= e((string) old('reservation_date', $today)) ?>"></div>
        <div class="col-6 col-md-2"><label class="form-label" for="expires_on">Expires on</label><input class="form-control<?= invalid('expires_on') ?>" type="date" id="expires_on" name="expires_on" value="<?= e((string) old('expires_on', $expires)) ?>"><?= $view->partial('partials/field-error', ['field' => 'expires_on']) ?></div>
        <div class="col-12"><input class="form-control" name="notes" value="<?= e((string) old('notes', '')) ?>" maxlength="500" placeholder="Notes" aria-label="Notes"></div>
    </div></div>
    <div class="card app-card mb-4"><div class="card-header">Items</div><div class="card-body"><?= $view->partial('inventory/_lines', ['mode' => 'reservation', 'lines' => $lines, 'items' => $items, 'units' => [], 'locations' => []]) ?>
        <p class="form-text mb-0 mt-3">Quantities are in each product's stock unit.</p></div></div>
    <?php if ($stock !== []): ?><details class="card app-card mb-4"><summary class="card-header">Free stock in <?= e($w['code']) ?></summary><?= $view->partial('inventory/_items', ['items' => $stock, 'showWarehouse' => false]) ?></details><?php endif; ?>
    <div class="form-actions"><a class="btn btn-light" href="<?= e(route('inventory.reservations')) ?>">Cancel</a><button class="btn btn-primary" type="submit"><i data-lucide="bookmark" class="icon-sm"></i>Reserve</button></div>
</form>
<?php endif; ?>
