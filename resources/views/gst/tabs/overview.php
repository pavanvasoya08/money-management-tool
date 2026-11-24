<?php
/** @var array<string, mixed> $reg @var array<string, mixed>|null $principal @var list<array<string, mixed>> $links @var array<string, string> $linkTypes @var list<array<string, mixed>> $statusHistory */
use App\Services\Gst\GstRegistrationService;
?>
<div class="kpi-group mb-4">
    <a class="stat-card" href="<?= e(route('gst.show', ['id' => $raw['id'], 'tab' => 'partners'])) ?>"><span class="stat-icon tone-violet"><i data-lucide="users-round"></i></span><span class="stat-label">Partners mapped</span><span class="stat-value"><?= (int) $partnerCount ?></span></a>
    <a class="stat-card" href="<?= e(route('gst.show', ['id' => $raw['id'], 'tab' => 'activities'])) ?>"><span class="stat-icon tone-blue"><i data-lucide="store"></i></span><span class="stat-label">Business activities</span><span class="stat-value"><?= (int) $activityCount ?></span></a>
    <a class="stat-card" href="<?= e(route('gst.show', ['id' => $raw['id'], 'tab' => 'locations'])) ?>"><span class="stat-icon tone-green"><i data-lucide="map-pin"></i></span><span class="stat-label">Active locations</span><span class="stat-value"><?= (int) $locationCount ?></span></a>
    <?php if (can('gst_registrations.download_documents')): ?><a class="stat-card" href="<?= e(route('gst.show', ['id' => $raw['id'], 'tab' => 'documents'])) ?>"><span class="stat-icon tone-amber"><i data-lucide="folder"></i></span><span class="stat-label">Documents</span><span class="stat-value"><?= (int) $documentCount ?></span></a><?php endif; ?>
</div>
<div class="row g-4">
    <div class="col-lg-6">
        <h2 class="card-section-title">Principal place of business</h2>
        <?php if ($principal === null): ?><p class="text-secondary">No principal place of business recorded.</p>
        <?php elseif (!empty($principal['hidden'])): ?><p class="text-secondary">Hidden by your field permissions.</p>
        <?php else: ?>
            <address class="mb-0"><?= e($principal['address_line1']) ?><?= $principal['address_line2'] ? '<br>' . e($principal['address_line2']) : '' ?><br>
                <?= e($principal['city']) ?><?= $principal['district'] ? ', ' . e($principal['district']) : '' ?><br><?= e($principal['state_name']) ?> <?= e($principal['pin_code']) ?> · <?= e($principal['country']) ?></address>
        <?php endif; ?>
    </div>
    <div class="col-lg-6">
        <h2 class="card-section-title">Recent status changes</h2>
        <?php if ($statusHistory === []): ?><p class="text-secondary">No status changes yet.</p><?php else: ?>
        <ul class="activity-list">
            <?php foreach ($statusHistory as $h): ?>
                <li><span class="activity-icon tone-slate"><i data-lucide="circle-dot"></i></span>
                    <div class="min-w-0 flex-grow-1"><div><?= $h['from_status'] ? e(GstRegistrationService::STATUSES[$h['from_status']] ?? $h['from_status']) . ' → ' : '' ?><b><?= e(GstRegistrationService::STATUSES[$h['to_status']] ?? $h['to_status']) ?></b></div>
                        <div class="small text-secondary"><?= e($h['reason'] ?? '') ?> · effective <?= e(format_date($h['effective_date'])) ?> · <?= e($h['changed_by_name'] ?? 'System') ?></div></div>
                    <span class="small text-secondary text-nowrap"><?= e(time_ago($h['created_at'])) ?></span></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<hr class="my-4">
<h2 class="card-section-title">Links to other modules</h2>
<?php if ($links === []): ?>
    <p class="text-secondary small mb-0">Bank accounts, e-commerce platforms, seller accounts, warehouses, products and sales or purchase records will link to this registration when those modules are released. Nothing is linked yet.</p>
<?php else: ?>
    <ul class="mb-0"><?php foreach ($links as $l): ?><li><?= e($l['type_label']) ?>: <?= e($l['label']) ?><?= $l['relationship'] ? ' (' . e($l['relationship']) . ')' : '' ?></li><?php endforeach; ?></ul>
<?php endif; ?>
