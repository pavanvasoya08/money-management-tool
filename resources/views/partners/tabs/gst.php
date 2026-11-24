<?php
/** GST registrations mapped to this partner (Phase 4). @var list<array<string, mixed>> $gstMappings */
$current = array_filter($gstMappings, static fn (array $m): bool => $m['status'] === 'active');
?>
<p class="text-secondary small">GST registrations this partner is associated with, and how. A partner can be linked to several registrations.
    These mappings do not affect the partner's capital or profit-sharing percentage.</p>
<?php if ($gstMappings === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'file-badge', 'text' => 'No GST registration is mapped to this partner' . (can('gst_registrations.manage_partners') ? '. Map one from the registration\'s Partner associations tab.' : '.')]) ?>
<?php else: ?>
<div class="small text-secondary mb-2"><?= count($current) ?> active · <?= count($gstMappings) - count($current) ?> ended</div>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Registration</th><th>State</th><th>Relationship</th><th>From</th><th>To</th><th>Registration status</th></tr></thead>
    <tbody><?php foreach ($gstMappings as $m): ?>
        <tr class="<?= $m['status'] !== 'active' ? 'text-secondary' : '' ?>">
            <td data-label="Registration"><a class="fw-semibold text-mono" href="<?= e(route('gst.show', ['id' => $m['gst_registration_id']])) ?>"><?= e($m['gstin'] ?? ('Registration #' . $m['gst_registration_id'])) ?></a>
                <span class="d-block small text-secondary"><?= e($m['legal_name'] ?? '') ?></span></td>
            <td data-label="State"><?= e($m['state_code'] . ' · ' . $m['state_name']) ?></td>
            <td data-label="Relationship"><?= e($m['relationship']) ?><?= $m['status'] !== 'active' ? ' <span class="badge badge-soft-secondary">ended</span>' : '' ?></td>
            <td data-label="From"><?= e(format_date($m['effective_from'])) ?></td>
            <td data-label="To"><?= e(format_date($m['effective_to'])) ?></td>
            <td data-label="Registration status"><?= $view->partial('gst/_status', ['status' => $m['registration_status'], 'archived' => (int) $m['is_archived'] === 1]) ?></td>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
