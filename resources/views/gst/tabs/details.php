<?php
/** Registration details tab. @var array<string, mixed> $reg (field rules applied) */
use App\Services\Gst\GstRegistrationService;

$row = static function (string $label, ?string $value, bool $mono = false): string {
    return '<div><dt>' . e($label) . '</dt><dd' . ($mono ? ' class="text-mono"' : '') . '>' . ($value !== null && $value !== '' ? e($value) : '<span class="text-secondary fw-normal">—</span>') . '</dd></div>';
};
$has = static fn (string $k): bool => array_key_exists($k, $reg);
?>
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <h2 class="card-section-title mb-0">Basic registration details</h2>
    <?php if (can('gst_registrations.edit') && $raw['is_archived'] !== 1): ?><a class="btn btn-sm btn-light" href="<?= e(route('gst.edit', ['id' => $raw['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a><?php endif; ?>
</div>
<dl class="detail-grid mb-4">
    <?= $has('gstin') ? $row('GSTIN', $reg['gstin'], true) : '' ?>
    <?= $has('legal_name') ? $row('Legal business name', $reg['legal_name']) : '' ?>
    <?= $has('trade_name') ? $row('Trade name', $reg['trade_name']) : '' ?>
    <?= $has('pan') ? $row('PAN', $reg['pan'], true) : '' ?>
    <?= $row('Registration type', $reg['type_name']) ?>
    <?= $row('Registration status', GstRegistrationService::STATUSES[$raw['status']] . ($raw['is_archived'] ? ' (archived)' : '')) ?>
    <?= $row('State', $reg['state_name']) ?>
    <?= $row('State code', $reg['state_code'], true) ?>
    <?= $row('Registration date', format_date($reg['registration_date'])) ?>
    <?= $row('Effective from', format_date($reg['effective_from'])) ?>
    <?php if ((int) $reg['has_validity_period'] === 1 || !empty($reg['validity_end_date'])): ?><?= $row('Validity end date', format_date($reg['validity_end_date'])) ?><?php endif; ?>
    <?php if ($raw['status'] === 'cancelled'): ?><?= $row('Cancellation date', format_date($reg['cancellation_date'])) ?><?php endif; ?>
    <?= $row('Business constitution', $reg['business_constitution']) ?>
    <?= $has('contact_person') ? $row('Primary contact person', $reg['contact_person']) : '' ?>
    <?= $has('contact_phone') ? $row('Contact number', $reg['contact_phone']) : '' ?>
    <?= $has('contact_email') ? $row('Email address', $reg['contact_email']) : '' ?>
</dl>
<h2 class="card-section-title">Additional business information</h2>
<dl class="detail-grid mb-4">
    <?= $row('Nature of business', $reg['nature_of_business']) ?>
    <?= $row('Taxpayer category', $reg['taxpayer_category']) ?>
    <?= $has('portal_reference') ? $row('GST portal reference', $reg['portal_reference']) : '' ?>
    <?= $row('Last updated', format_datetime($reg['updated_at']) . (!empty($reg['updated_by_name']) ? ' by ' . $reg['updated_by_name'] : '')) ?>
</dl>
<?php if ($has('internal_notes') && !empty($reg['internal_notes'])): ?>
    <h2 class="card-section-title">Internal notes</h2>
    <p class="text-prewrap"><?= e($reg['internal_notes']) ?></p>
<?php endif; ?>
<p class="small text-secondary mb-0"><i data-lucide="info" class="icon-xs"></i> The GSTIN format and check character were validated when saved. This does not confirm the registration with the GST portal.</p>
