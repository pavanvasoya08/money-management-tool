<?php
/** @var array<string, mixed> $s @var array<string, mixed> $fields */
use App\Services\Supplier\SupplierService;

$m = static function (string $field, ?string $v) use ($fields): ?string {
    if (isset($fields[$field]) && !$fields[$field]->canView()) {
        return null;
    }
    if ($v === null || $v === '') {
        return '—';
    }
    return isset($fields[$field]) && !$fields[$field]->showsRealValue() ? \App\Services\Authorization\FieldPolicy::mask($v, \App\Core\App::fields()->maskType('suppliers', $field)) : $v;
};
?>
<div class="d-flex justify-content-between align-items-start mb-2"><h2 class="card-section-title mb-0">Business information</h2>
    <?php if ($s['status'] !== 'archived' && can('suppliers.edit')): ?><a class="btn btn-sm btn-light" href="<?= e(route('suppliers.edit', ['id' => $s['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a><?php endif; ?></div>
<dl class="detail-grid">
    <div><dt>Supplier name</dt><dd><?= e($s['name']) ?></dd></div><div><dt>Legal business name</dt><dd><?= e($s['legal_name']) ?></dd></div>
    <div><dt>Supplier code</dt><dd><?= e($s['supplier_code']) ?></dd></div><div><dt>Supplier type</dt><dd><?= e($s['type_name']) ?></dd></div>
    <div><dt>Website</dt><dd><?= $s['website'] ? '<a href="' . e($s['website']) . '" target="_blank" rel="noopener noreferrer">' . e($s['website']) . '</a>' : '—' ?></dd></div>
    <div><dt>Country</dt><dd><?= e($s['country']) ?></dd></div>
    <?php if (($v = $m('payment_terms', $s['payment_terms'])) !== null): ?><div><dt>Payment terms</dt><dd><?= e($v) ?></dd></div><div><dt>Credit period</dt><dd><?= $s['credit_period_days'] !== null ? (int) $s['credit_period_days'] . ' days' : '—' ?></dd></div><?php endif; ?>
    <div><dt>Default currency</dt><dd><?= e($s['default_currency']) ?></dd></div>
    <div><dt>Status</dt><dd><?= e(SupplierService::STATUSES[$s['status']]) ?></dd></div>
    <div><dt>Created</dt><dd><?= e(format_datetime($s['created_at'])) ?><span class="d-block small text-secondary"><?= e($s['created_by_name'] ?? '—') ?></span></dd></div>
    <div><dt>Last updated</dt><dd><?= e(format_datetime($s['updated_at'])) ?><span class="d-block small text-secondary"><?= e($s['updated_by_name'] ?? '—') ?></span></dd></div>
    <?php if (($v = $m('internal_notes', $s['internal_notes'])) !== null): ?><div class="span-2"><dt>Internal notes</dt><dd class="text-pre-line"><?= e($v) ?></dd></div><?php endif; ?>
</dl>
