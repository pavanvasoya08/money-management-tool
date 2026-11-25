<?php
/** @var array<string, mixed> $s @var list<array<string, mixed>> $mappings @var list<array<string, mixed>> $gstOptions @var array<string, mixed> $fields */
use App\Services\Supplier\SupplierService;

$see = static fn (string $k): bool => !isset($fields[$k]) || $fields[$k]->canView();
$masked = static fn (string $k): bool => isset($fields[$k]) && !$fields[$k]->showsRealValue();
$sid = (int) $s['id'];
?>
<div class="d-flex justify-content-between align-items-start mb-2"><h2 class="card-section-title mb-0">Supplier GST details</h2>
    <?php if ($s['status'] !== 'archived' && can('suppliers.edit') && can('suppliers.manage_gst_details')): ?><a class="btn btn-sm btn-light" href="<?= e(route('suppliers.edit', ['id' => $sid])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Change GST details</a><?php endif; ?></div>
<dl class="detail-grid mb-2">
    <div><dt>GST treatment</dt><dd><?= e(SupplierService::GST_TREATMENTS[$s['gst_treatment']]) ?></dd></div>
    <?php if ($see('gstin')): ?><div><dt>GSTIN</dt><dd class="text-mono"><?= e($s['gstin'] ? ($masked('gstin') ? \App\Services\Authorization\FieldPolicy::mask((string) $s['gstin'], 'full') : $s['gstin']) : '—') ?></dd></div><?php endif; ?>
    <?php if ($see('pan')): ?><div><dt>PAN</dt><dd class="text-mono"><?= e($s['pan'] ? ($masked('pan') ? \App\Services\Authorization\FieldPolicy::mask((string) $s['pan'], 'last4') : $s['pan']) : '—') ?></dd></div><?php endif; ?>
    <div><dt>State (from GSTIN)</dt><dd><?= e($s['state_name'] ? $s['state_code'] . ' · ' . $s['state_name'] : '—') ?></dd></div>
</dl>
<p class="small text-secondary mb-4"><i data-lucide="info" class="icon-sm"></i> The GSTIN is checked for structure, state code, PAN segment and check character only — it has not been verified with the GST portal. Changes to GSTIN, PAN, treatment or legal name of an active supplier wait for approval when the supplier GSTIN workflow is active.</p>
<h2 class="card-section-title">Our GST registrations that buy from this supplier</h2>
<?= $view->partial('products/_dated', [
    'mappings' => $mappings, 'options' => $gstOptions, 'canManage' => $s['status'] !== 'archived' && can('suppliers.manage_business_mappings') && $gstOptions !== [],
    'addUrl' => route('suppliers.gst.store', ['id' => $sid]), 'endUrl' => static fn (int $m): string => route('suppliers.gst.end', ['id' => $sid, 'mapping' => $m]),
    'field' => 'gst_registration_id', 'label' => 'GST registration', 'targetRoute' => can('gst_registrations.view') ? 'gst.show' : null,
    'intro' => 'Links this supplier to the company GST registrations it supplies (reused from GST Management). This prepares purchases in Phase 11 and never implies ownership.',
    'empty' => 'Not linked to any of our GST registrations.',
]) ?>
