<?php
/**
 * Platform page: summary card + tabs (Overview, Configuration, GST, Business, Warehouses, Seller accounts placeholder, Documents, Notes, History).
 * @var array<string, mixed> $p @var string $tab @var array<string, string> $tabs @var array<string, mixed> $fields @var array<string, mixed> $readiness
 * @var bool $writable @var array<string, int> $counts
 */
use App\Services\Ecommerce\PlatformHistory;
use App\Services\Ecommerce\PlatformService;
use App\Support\EcommerceView;
use App\Support\Locale;

$pid = (int) $p['id'];
$archived = $p['status'] === 'archived';
$see = static fn (string $k): bool => !isset($fields[$k]) || $fields[$k]->canView();
$err = static fn (string $f): string => $view->partial('partials/field-error', ['field' => $f]);
$tabUrl = static fn (string $t, array $q = []): string => route('ecommerce.platforms.show', ['id' => $pid, 'tab' => $t] + $q);
$notesSvc = $noteService ?? null;
?>
<div class="row g-4">
    <div class="col-xl-4 col-xxl-3">
        <div class="card app-card profile-card">
            <?php if ($writable && can('platforms.edit')): ?><div class="corner-action"><a class="btn btn-icon" href="<?= e(route('ecommerce.platforms.edit', ['id' => $pid])) ?>" aria-label="Edit platform" title="Edit platform"><i data-lucide="pencil" class="icon-sm"></i></a></div><?php endif; ?>
            <div class="profile-top"><?= EcommerceView::logo($p, 'xl') ?>
                <div class="min-w-0"><h1 class="product-title"><?= e($p['name']) ?></h1><div class="sub d-flex flex-wrap gap-1"><?= EcommerceView::badge((string) $p['status']) ?> <?= EcommerceView::badge((string) $p['connection_status']) ?></div></div></div>
            <dl class="detail-stack">
                <div><dt>Code</dt><dd class="text-mono"><?= e($p['platform_code']) ?></dd></div>
                <div><dt>Category</dt><dd><?= e($p['category_name']) ?></dd></div>
                <?php if ($see('website_url')): ?><div><dt>Website</dt><dd><?php if ($p['website_url']): ?><a href="<?= e($p['website_url']) ?>" target="_blank" rel="noopener noreferrer"><?= e(preg_replace('#^https?://#', '', (string) $p['website_url'])) ?></a><?php else: ?>—<?php endif; ?></dd></div><?php endif; ?>
                <div><dt>Country</dt><dd><?= e(Locale::COUNTRIES[$p['country']] ?? $p['country']) ?></dd></div>
                <div><dt>Currencies</dt><dd><?= e(str_replace(',', ', ', (string) $p['currencies'])) ?> <span class="text-secondary">· default <?= e($p['default_currency']) ?></span></dd></div>
                <div><dt>Configured</dt><dd><?= EcommerceView::readiness($readiness) ?></dd></div>
                <?php if ($p['status_reason']): ?><div><dt>Status note</dt><dd><?= e($p['status_reason']) ?></dd></div><?php endif; ?>
                <div><dt>Added</dt><dd class="small"><?= e(format_datetime($p['created_at'])) ?><?= $p['created_by_name'] ? ' · ' . e($p['created_by_name']) : '' ?></dd></div>
            </dl>
            <div class="profile-actions">
                <?php if (!$archived && $p['status'] !== 'active' && can('platforms.activate')): $missing = array_keys(array_filter($readiness['required'], static fn (bool $ok): bool => !$ok)); ?>
                    <form method="post" action="<?= e(route('ecommerce.platforms.status', ['id' => $pid])) ?>" class="w-100"><?= csrf_field() ?><input type="hidden" name="status" value="active">
                        <button class="btn btn-light w-100" type="submit" <?= $missing !== [] ? 'disabled title="Complete: ' . e(implode(', ', $missing)) . '"' : '' ?>><i data-lucide="circle-play"></i>Activate</button></form>
                <?php endif; ?>
                <?php if (!$archived && $p['status'] !== 'inactive' && can('platforms.deactivate')): ?>
                    <details class="inline-edit w-100"><summary class="btn btn-light w-100"><i data-lucide="circle-pause"></i>Deactivate</summary>
                        <form method="post" action="<?= e(route('ecommerce.platforms.status', ['id' => $pid])) ?>" class="inline-edit-body"><?= csrf_field() ?><input type="hidden" name="status" value="inactive">
                            <p class="small text-secondary mb-2">Deactivating deletes nothing. Mappings, documents and history are kept.</p>
                            <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Deactivate platform</button></form></details>
                <?php endif; ?>
                <?php if (!$archived && can('platforms.archive')): $check = $archiveCheck ?? null; ?>
                    <details class="inline-edit w-100"><summary class="btn btn-light w-100 text-danger"><i data-lucide="archive"></i>Archive</summary>
                        <form method="post" action="<?= e(route('ecommerce.platforms.archive', ['id' => $pid])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                            <p class="small text-secondary mb-2">An archived platform becomes read-only. It is never deleted and can be restored.</p>
                            <?php if (!empty($check['warnings'])): ?><div class="alert alert-warning small py-2 mb-2"><b>Still linked:</b><ul class="mb-0 ps-3"><?php foreach ($check['warnings'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul></div>
                                <div class="form-check small mb-2"><input class="form-check-input" type="checkbox" id="arc-confirm" name="confirm" value="1" required><label class="form-check-label" for="arc-confirm">I understand — archive anyway</label></div><?php endif; ?>
                            <?php if (!isset($check)): ?><p class="small text-secondary">Open the Overview tab to see what is still linked.</p><?php endif; ?>
                            <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><?= $err('confirm') ?>
                            <button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive platform</button></form></details>
                <?php endif; ?>
                <?php if ($archived && can('platforms.archive')): ?>
                    <form method="post" action="<?= e(route('ecommerce.platforms.restore', ['id' => $pid])) ?>" class="w-100"><?= csrf_field() ?><button class="btn btn-light w-100" type="submit"><i data-lucide="archive-restore"></i>Restore</button></form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-xl-8 col-xxl-9 min-w-0">
        <?php if ($archived): ?><div class="alert alert-secondary small"><i data-lucide="archive" class="icon-sm"></i> Archived<?= $p['status_reason'] ? ': ' . e($p['status_reason']) : '' ?>. The platform, its mappings and history are read-only.</div><?php endif; ?>
        <?php if ($p['status'] === 'inactive'): ?><div class="alert alert-warning small"><i data-lucide="circle-pause" class="icon-sm"></i> Inactive<?= $p['status_reason'] ? ': ' . e($p['status_reason']) : '' ?>. It can still be configured; activate it to use it again.</div><?php endif; ?>
        <?php if ($p['status'] === 'under_configuration'): ?><div class="alert alert-info small"><i data-lucide="settings-2" class="icon-sm"></i> Under configuration — complete the checklist on the Overview tab, then activate the platform.</div><?php endif; ?>
        <div class="card app-card tabs-card">
            <ul class="nav app-tabs" role="tablist">
                <?php foreach ($tabs as $key => $label): $n = ['gst' => $counts['gst'], 'business' => $counts['business'], 'warehouses' => $counts['warehouses'], 'documents' => $counts['documents'], 'notes' => $counts['notes']][$key] ?? null; ?>
                    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : '' ?>" href="<?= e($tabUrl($key)) ?>" <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= e($label) ?><?= $n ? ' <span class="tab-count">' . (int) $n . '</span>' : '' ?></a></li>
                <?php endforeach; ?>
            </ul>
            <div class="tab-body">
<?php if ($tab === 'overview'): ?>
    <div class="row g-4">
        <div class="col-lg-7">
            <h2 class="h6">About</h2>
            <p class="small"><?= $p['description'] ? nl2br(e($p['description'])) : '<span class="text-secondary">No description.</span>' ?></p>
            <dl class="detail-grid mb-4">
                <div><dt>Languages</dt><dd><?= e(Locale::names((string) $p['languages'], Locale::LANGUAGES)) ?></dd></div>
                <div><dt>Time zone</dt><dd><?= e($p['time_zone']) ?></dd></div>
                <div><dt>Date format</dt><dd><?= e(Locale::DATE_FORMATS[$p['date_format']] ?? $p['date_format']) ?></dd></div>
                <div><dt>Number format</dt><dd><?= e(Locale::NUMBER_FORMATS[$p['number_format']] ?? $p['number_format']) ?></dd></div>
                <div><dt>Reference prefix</dt><dd class="text-mono"><?= e($p['reference_prefix'] ?: '—') ?></dd></div>
                <div><dt>Business currency</dt><dd><?= e($p['business_currency']) ?></dd></div>
                <div><dt>Display order</dt><dd><?= (int) $p['display_order'] ?></dd></div>
                <div><dt>Last changed</dt><dd class="small"><?= e(format_datetime($p['updated_at'])) ?><?= $p['updated_by_name'] ? ' · ' . e($p['updated_by_name']) : '' ?></dd></div>
            </dl>
            <?php if ($see('internal_notes') && $p['internal_notes']): ?><h2 class="h6">Internal notes</h2><p class="small"><?= nl2br(e($p['internal_notes'])) ?></p><?php endif; ?>
            <h2 class="h6">Capabilities</h2>
            <div class="table-responsive"><table class="table app-table table-sm mb-0 table-stack"><thead><tr><th>Capability</th><th>Platform offers it</th><th>In this ERP</th></tr></thead><tbody>
                <?php foreach ($capabilities as $c): ?><tr><td data-label="Capability"><?= e($c['label']) ?></td><td data-label="Platform offers it"><?= EcommerceView::badge($c['platform_support'], ['unknown' => 'Unknown', 'supported' => 'Yes', 'not_supported' => 'No']) ?></td>
                    <td data-label="In this ERP"><?= EcommerceView::badge($c['erp_status'], ['not_available' => 'Not yet', 'planned' => 'Planned', 'operational' => 'Operational']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
        <div class="col-lg-5">
            <div class="card app-card bg-fill mb-4"><div class="card-body">
                <h2 class="h6">Configuration checklist</h2>
                <p class="small text-secondary mb-2">Required to activate</p>
                <ul class="readiness-list mb-3"><?php foreach ($readiness['required'] as $k => $ok): ?><li class="<?= $ok ? 'ok' : 'todo' ?>"><i data-lucide="<?= $ok ? 'circle-check' : 'circle' ?>" class="icon-sm"></i><?= e(ucfirst($k)) ?></li><?php endforeach; ?></ul>
                <p class="small text-secondary mb-2">Recommended</p>
                <ul class="readiness-list"><?php foreach ($readiness['recommended'] as $k => $ok): ?><li class="<?= $ok ? 'ok' : 'todo' ?>"><i data-lucide="<?= $ok ? 'circle-check' : 'circle' ?>" class="icon-sm"></i><?= e(ucfirst($k)) ?></li><?php endforeach; ?></ul>
            </div></div>
            <?php if (!empty($archiveCheck['warnings'])): ?><div class="card app-card mb-4"><div class="card-body small"><h2 class="h6">Linked to this platform</h2><ul class="mb-0 ps-3"><?php foreach ($archiveCheck['warnings'] as $w): ?><li><?= e(preg_replace('/ \(.*\)$/', '', $w)) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
            <?php if (can('platforms.view_history')): ?><h2 class="h6">Recent changes</h2>
                <?php if ($recent === []): ?><p class="small text-secondary">No changes yet.</p><?php else: ?><ul class="activity-list mb-0"><?php foreach ($recent as $h): ?>
                    <li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="min-w-0 flex-grow-1"><div><?= e(PlatformHistory::label($h['event'])) ?></div><div class="small text-secondary"><?= e($h['actor_name'] ?? 'System') ?> · <?= e(time_ago($h['created_at'])) ?></div></div></li>
                <?php endforeach; ?></ul><?php endif; ?><?php endif; ?>
        </div>
    </div>

<?php elseif ($tab === 'configuration'): $can = $writable && can('platforms.manage_configurations'); $dis = $can ? '' : ' disabled'; $sup = Locale::codes((string) $p['currencies']); $langs = Locale::codes((string) $p['languages']); ?>
    <form method="post" action="<?= e(route('ecommerce.platforms.configure', ['id' => $pid])) ?>" data-loading class="mb-4"><?= csrf_field() ?>
        <h2 class="h6">General configuration</h2>
        <div class="row g-3">
            <div class="col-md-6"><fieldset><legend class="form-label">Supported currencies <span class="text-danger">*</span></legend><div class="d-flex flex-wrap gap-3">
                <?php foreach (Locale::CURRENCIES as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="cur-<?= e($k) ?>" name="currencies[]" value="<?= e($k) ?>" <?= in_array($k, $sup, true) ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label" for="cur-<?= e($k) ?>" title="<?= e($l) ?>"><?= e($k) ?></label></div><?php endforeach; ?>
            </div><?= $err('currencies') ?></fieldset></div>
            <div class="col-md-3"><label class="form-label" for="c-def">Default currency</label><select class="form-select<?= invalid('default_currency') ?>" id="c-def" name="default_currency"<?= $dis ?>><?php foreach (Locale::CURRENCIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $p['default_currency'] === $k ? 'selected' : '' ?>><?= e($k) ?></option><?php endforeach; ?></select><?= $err('default_currency') ?></div>
            <div class="col-md-3"><label class="form-label" for="c-country">Country</label><select class="form-select<?= invalid('country') ?>" id="c-country" name="country"<?= $dis ?>><?php foreach (Locale::COUNTRIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $p['country'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6"><fieldset><legend class="form-label">Languages <span class="text-danger">*</span></legend><div class="d-flex flex-wrap gap-3">
                <?php foreach (Locale::LANGUAGES as $k => $l): ?><div class="form-check"><input class="form-check-input" type="checkbox" id="lang-<?= e($k) ?>" name="languages[]" value="<?= e($k) ?>" <?= in_array($k, $langs, true) ? 'checked' : '' ?><?= $dis ?>><label class="form-check-label" for="lang-<?= e($k) ?>"><?= e($l) ?></label></div><?php endforeach; ?>
            </div><?= $err('languages') ?></fieldset></div>
            <div class="col-md-6"><label class="form-label" for="c-tz">Time zone</label><select class="form-select<?= invalid('time_zone') ?>" id="c-tz" name="time_zone"<?= $dis ?>><?php foreach ($zones as $z): ?><option value="<?= e($z) ?>" <?= $p['time_zone'] === $z ? 'selected' : '' ?>><?= e($z) ?></option><?php endforeach; ?></select><?= $err('time_zone') ?></div>
            <div class="col-md-4"><label class="form-label" for="c-date">Date format</label><select class="form-select" id="c-date" name="date_format"<?= $dis ?>><?php foreach (Locale::DATE_FORMATS as $k => $l): ?><option value="<?= e($k) ?>" <?= $p['date_format'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="c-num">Number format</label><select class="form-select" id="c-num" name="number_format"<?= $dis ?>><?php foreach (Locale::NUMBER_FORMATS as $k => $l): ?><option value="<?= e($k) ?>" <?= $p['number_format'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-4"><label class="form-label" for="c-prefix">Reference prefix</label><input class="form-control text-uppercase text-mono<?= invalid('reference_prefix') ?>" id="c-prefix" name="reference_prefix" value="<?= e((string) old('reference_prefix', $p['reference_prefix'] ?? '')) ?>" maxlength="12" placeholder="e.g. AMZ"<?= $dis ?>><div class="form-text">Used for this platform's references in later phases.</div><?= $err('reference_prefix') ?></div>
        </div>
        <?php if ($can): ?><div class="mt-3"><button class="btn btn-primary" type="submit"><i data-lucide="save" class="icon-sm"></i>Save configuration</button></div><?php endif; ?>
    </form>

    <form method="post" action="<?= e(route('ecommerce.platforms.capabilities', ['id' => $pid])) ?>" data-loading class="mb-4"><?= csrf_field() ?>
        <h2 class="h6">Capabilities</h2>
        <p class="small text-secondary">Record what the platform offers. “Operational” is only possible once the ERP really implements the capability — none are implemented yet; they arrive in the phases shown.</p>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack capability-table"><thead><tr><th>Capability</th><th>Offered by the platform</th><th>In this ERP</th><th>Notes</th></tr></thead><tbody>
            <?php foreach ($capabilities as $c): $k = $c['capability_key']; ?><tr>
                <td data-label="Capability"><b><?= e($c['label']) ?></b><span class="d-block small text-secondary"><?= e($c['phase']) ?></span><?= $err('capabilities.' . $k) ?></td>
                <td data-label="Platform"><select class="form-select form-select-sm" name="capabilities[<?= e($k) ?>][platform_support]" aria-label="<?= e($c['label']) ?> offered"<?= $dis ?>><?php foreach (PlatformService::PLATFORM_SUPPORT as $sk => $sl): ?><option value="<?= e($sk) ?>" <?= $c['platform_support'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select></td>
                <td data-label="ERP"><select class="form-select form-select-sm" name="capabilities[<?= e($k) ?>][erp_status]" aria-label="<?= e($c['label']) ?> in the ERP"<?= $dis ?>><?php foreach (PlatformService::ERP_STATUS as $sk => $sl): if ($sk === 'operational' && !$c['implemented']) { continue; } ?><option value="<?= e($sk) ?>" <?= $c['erp_status'] === $sk ? 'selected' : '' ?>><?= e($sl) ?></option><?php endforeach; ?></select></td>
                <td data-label="Notes"><input class="form-control form-control-sm" name="capabilities[<?= e($k) ?>][notes]" value="<?= e((string) ($c['notes'] ?? '')) ?>" maxlength="255" aria-label="<?= e($c['label']) ?> notes"<?= $dis ?>></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($can): ?><div class="mt-3"><button class="btn btn-primary" type="submit"><i data-lucide="save" class="icon-sm"></i>Save capabilities</button></div><?php endif; ?>
    </form>

    <h2 class="h6">Platform-specific settings</h2>
    <p class="small text-secondary">Extra key–value settings for this platform (e.g. <span class="text-mono">return_window_days = 7</span>). Passwords, tokens and API keys are refused.</p>
    <?php if ($settings !== []): ?><div class="table-responsive mb-3"><table class="table app-table table-sm mb-0 table-stack"><thead><tr><th>Key</th><th>Value</th><th>Description</th><th>Changed by</th></tr></thead><tbody>
        <?php foreach ($settings as $s): ?><tr><td data-label="Key" class="text-mono"><?= e($s['setting_key']) ?></td><td data-label="Value"><?= e($s['value']) ?></td><td data-label="Description" class="small"><?= e($s['description'] ?? '—') ?></td><td data-label="Changed by" class="small"><?= e($s['updated_by_name'] ?? '—') ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php else: ?><p class="small text-secondary">No custom settings.</p><?php endif; ?>
    <?php if ($can): ?><form method="post" action="<?= e(route('ecommerce.platforms.settings', ['id' => $pid])) ?>" class="row g-2 align-items-end" data-loading><?= csrf_field() ?>
        <div class="col-md-3"><label class="form-label small" for="s-key">Key</label><input class="form-control form-control-sm text-mono<?= invalid('setting_key') ?>" id="s-key" name="setting_key" maxlength="60" required placeholder="return_window_days"><?= $err('setting_key') ?></div>
        <div class="col-md-3"><label class="form-label small" for="s-val">Value</label><input class="form-control form-control-sm<?= invalid('value') ?>" id="s-val" name="value" maxlength="500"><?= $err('value') ?></div>
        <div class="col-md-4"><label class="form-label small" for="s-desc">Description</label><input class="form-control form-control-sm" id="s-desc" name="description" maxlength="255"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit">Save setting</button></div>
        <div class="col-12 form-text">Saving an existing key with an empty value removes it.</div>
    </form><?php endif; ?>

<?php elseif ($tab === 'gst'): ?>
    <?= $view->partial('products/_dated', ['mappings' => $mappings, 'options' => $gstOptions, 'canManage' => $writable && can('platforms.manage_gst_mappings') && $gstOptions !== [],
        'addUrl' => route('ecommerce.platforms.mappings.store', ['id' => $pid, 'kind' => 'gst']), 'endUrl' => static fn (int $m): string => route('ecommerce.platforms.mappings.end', ['id' => $pid, 'kind' => 'gst', 'mapping' => $m]),
        'field' => 'gst_registration_id', 'label' => 'GST registration', 'targetRoute' => can('gst_registrations.view') ? 'gst.show' : null,
        'intro' => 'GST registrations the business uses on this platform. A platform can be used with several registrations (one per state where it sells). This sets defaults only; it does not decide who owns the sales.',
        'empty' => 'No GST registration is linked to this platform.']) ?>

<?php elseif ($tab === 'business'): $canBiz = $writable && can('platforms.manage_business_mappings'); ?>
    <form method="post" action="<?= e(route('ecommerce.platforms.currency', ['id' => $pid])) ?>" class="row g-2 align-items-end mb-4" data-loading><?= csrf_field() ?>
        <div class="col-md-5"><label class="form-label" for="b-cur">Default business currency</label><select class="form-select<?= invalid('business_currency') ?>" id="b-cur" name="business_currency"<?= $canBiz ? '' : ' disabled' ?>><?php foreach (Locale::CURRENCIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $p['business_currency'] === $k ? 'selected' : '' ?>><?= e($k . ' — ' . $l) ?></option><?php endforeach; ?></select>
            <div class="form-text">The currency the business records this channel in.</div></div>
        <?php if ($canBiz): ?><div class="col-md-2"><button class="btn btn-light" type="submit">Save</button></div><?php endif; ?>
    </form>
    <h2 class="h6">Business activities</h2>
    <?= $view->partial('products/_dated', ['mappings' => $activities, 'options' => $activityOptions, 'canManage' => $canBiz,
        'addUrl' => route('ecommerce.platforms.mappings.store', ['id' => $pid, 'kind' => 'activities']), 'endUrl' => static fn (int $m): string => route('ecommerce.platforms.mappings.end', ['id' => $pid, 'kind' => 'activities', 'mapping' => $m]),
        'field' => 'business_activity_id', 'label' => 'Business activity', 'targetRoute' => null,
        'intro' => 'Business activities (lines of business) this platform is used for.', 'empty' => 'No business activity is linked.']) ?>
    <h2 class="h6 mt-4">Partners</h2>
    <?php if ($partners === null): ?><p class="small text-secondary">Partner mappings are hidden by your field permissions.</p><?php else: ?>
    <?= $view->partial('products/_dated', ['mappings' => $partners, 'options' => $partnerOptions, 'canManage' => $canBiz && ($fields['partner_mappings'] ?? null)?->canEdit() !== false && $partnerOptions !== [],
        'addUrl' => route('ecommerce.platforms.mappings.store', ['id' => $pid, 'kind' => 'partners']), 'endUrl' => static fn (int $m): string => route('ecommerce.platforms.mappings.end', ['id' => $pid, 'kind' => 'partners', 'mapping' => $m]),
        'field' => 'partner_id', 'label' => 'Partner', 'targetRoute' => can('partners.view') ? 'partners.show' : null,
        'intro' => 'Partners permitted to work with this platform. This is a scope only — it creates no ownership, capital or profit-sharing rights.', 'empty' => 'No partner is linked.']) ?>
    <?php endif; ?>

<?php elseif ($tab === 'warehouses'): ?>
    <?= $view->partial('products/_dated', ['mappings' => $mappings, 'options' => $warehouseOptions, 'canManage' => $writable && can('platforms.manage_warehouse_mappings') && $warehouseOptions !== [],
        'addUrl' => route('ecommerce.platforms.mappings.store', ['id' => $pid, 'kind' => 'warehouses']), 'endUrl' => static fn (int $m): string => route('ecommerce.platforms.mappings.end', ['id' => $pid, 'kind' => 'warehouses', 'mapping' => $m]),
        'field' => 'warehouse_id', 'label' => 'Warehouse', 'targetRoute' => can('inventory.view') ? 'inventory.warehouses.show' : null,
        'intro' => 'Warehouses that fulfil orders for this platform (including marketplace fulfilment centres). Stock sync is not available yet.',
        'empty' => can('inventory.view') ? 'No warehouse is linked to this platform.' : 'No warehouse is linked. You need Inventory access to choose warehouses.']) ?>

<?php elseif ($tab === 'accounts'): ?>
    <div class="placeholder-panel text-center"><span class="stat-icon tone-slate mx-auto mb-2"><i data-lucide="user-round"></i></span>
        <h2 class="h6">Seller accounts arrive in Phase 8B</h2>
        <p class="small text-secondary mb-0">Each seller account on this platform — with its GST registration, partner, warehouses and encrypted API credentials — will be managed here. Nothing is stored yet.</p></div>

<?php elseif ($tab === 'documents'): $canUp = $writable && can('platforms.manage_documents'); ?>
    <?php if ($canUp): ?>
    <form class="row g-2 align-items-end mb-3" method="post" action="<?= e(route('ecommerce.platforms.documents.store', ['id' => $pid])) ?>" enctype="multipart/form-data" data-loading><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small" for="d-title">Title <span class="text-danger">*</span></label><input class="form-control form-control-sm<?= invalid('title') ?>" id="d-title" name="title" maxlength="150" required><?= $err('title') ?></div>
        <div class="col-md-3"><label class="form-label small" for="d-type">Type</label><select class="form-select form-select-sm" id="d-type" name="document_type"><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label small" for="d-file">File <span class="text-danger">*</span></label><input class="form-control form-control-sm<?= invalid('file') ?>" type="file" id="d-file" name="file" required><?= $err('file') ?></div>
        <div class="col-md-10"><input class="form-control form-control-sm" name="description" maxlength="500" placeholder="Description (optional)" aria-label="Description"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit"><i data-lucide="upload" class="icon-sm"></i>Upload</button></div>
        <div class="col-12 form-text">PDF, images, Office documents, CSV or text, up to 10 MB. Files are stored privately and checked by content. Never upload password lists or API keys.</div>
    </form>
    <?php endif; ?>
    <div class="d-flex justify-content-end mb-2"><a class="small" href="<?= e($tabUrl('documents', $showArchived ? [] : ['archived' => 1])) ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a></div>
    <?php if (!can('platforms.download_documents') && !can('platforms.manage_documents')): ?><p class="text-secondary small mb-0">You do not have access to documents.</p>
    <?php elseif ($documents === []): ?><?= $view->partial('partials/empty', ['icon' => 'file-text', 'text' => 'No documents.']) ?>
    <?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack"><thead><tr><th>Document</th><th>Type</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead><tbody>
        <?php foreach ($documents as $d): ?><tr class="<?= $d['status'] !== 'active' ? 'text-secondary' : '' ?>">
            <td data-label="Document"><b><?= e($d['title']) ?></b><span class="d-block small text-secondary text-break"><?= e($d['original_name']) ?> · <?= e(number_format(((int) $d['size_bytes']) / 1024, 1)) ?> KB<?= $d['status'] !== 'active' ? ' · archived' . ($d['archive_reason'] ? ': ' . e($d['archive_reason']) : '') : '' ?></span><?= $d['description'] ? '<span class="d-block small">' . e($d['description']) . '</span>' : '' ?></td>
            <td data-label="Type"><?= e($types[$d['document_type']] ?? $d['document_type']) ?></td>
            <td data-label="Uploaded" class="small"><?= e($d['uploaded_by_name'] ?? '—') ?><span class="d-block text-secondary"><?= e(format_datetime($d['created_at'])) ?></span></td>
            <td data-label="Actions" class="text-end text-nowrap">
                <?php if (can('platforms.download_documents')): ?><a class="btn btn-sm btn-icon" href="<?= e(route('ecommerce.platforms.documents.show', ['id' => $pid, 'doc' => $d['id'], 'inline' => 1])) ?>" target="_blank" rel="noopener" title="View" aria-label="View <?= e($d['title']) ?>"><i data-lucide="eye" class="icon-sm"></i></a>
                    <a class="btn btn-sm btn-icon" href="<?= e(route('ecommerce.platforms.documents.show', ['id' => $pid, 'doc' => $d['id']])) ?>" title="Download" aria-label="Download <?= e($d['title']) ?>"><i data-lucide="download" class="icon-sm"></i></a><?php endif; ?>
                <?php if ($d['status'] === 'active' && $canUp): ?><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-icon text-danger" title="Archive" aria-label="Archive document"><i data-lucide="archive" class="icon-sm"></i></summary>
                    <form method="post" action="<?= e(route('ecommerce.platforms.documents.archive', ['id' => $pid, 'doc' => $d['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                        <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required)" required maxlength="255" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive document</button></form></details><?php endif; ?>
            </td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>

<?php elseif ($tab === 'notes'): $canNote = $writable && can('platforms.manage_notes'); $en = $editNote; ?>
    <?php if ($canNote): ?>
    <form class="card app-card bg-fill mb-4" method="post" action="<?= e(route('ecommerce.platforms.notes.store', ['id' => $pid])) ?>" data-loading><div class="card-body row g-2"><?= csrf_field() ?>
        <?php if ($en): ?><input type="hidden" name="note_id" value="<?= (int) $en['id'] ?>"><?php endif; ?>
        <div class="col-md-8"><label class="form-label small" for="n-title">Title <span class="text-danger">*</span></label><input class="form-control<?= invalid('title') ?>" id="n-title" name="title" maxlength="150" required value="<?= e((string) old('title', $en['title'] ?? '')) ?>"><?= $err('title') ?></div>
        <div class="col-md-4"><label class="form-label small" for="n-vis">Visible to</label><select class="form-select" id="n-vis" name="visibility"><?php foreach ($visibility as $k => $l): ?><option value="<?= e($k) ?>" <?= (string) old('visibility', $en['visibility'] ?? 'everyone') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small" for="n-body">Note <span class="text-danger">*</span></label><textarea class="form-control<?= invalid('body') ?>" id="n-body" name="body" rows="3" maxlength="10000" required><?= e((string) old('body', $en['body'] ?? '')) ?></textarea><?= $err('body') ?></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit"><?= $en ? 'Save note' : 'Add note' ?></button><?php if ($en): ?><a class="btn btn-light" href="<?= e($tabUrl('notes')) ?>">Cancel</a><?php endif; ?></div>
    </div></form>
    <?php endif; ?>
    <div class="d-flex justify-content-end mb-2"><a class="small" href="<?= e($tabUrl('notes', $showArchived ? [] : ['archived' => 1])) ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a></div>
    <?php if ($notes === []): ?><?= $view->partial('partials/empty', ['icon' => 'sticky-note', 'text' => 'No notes you can see.']) ?><?php else: ?>
    <ul class="list-unstyled mb-0"><?php foreach ($notes as $n): ?><li class="border-bottom py-3 <?= $n['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <div class="d-flex flex-wrap align-items-center gap-2"><b><?= e($n['title']) ?></b>
            <span class="badge rounded-pill badge-soft-<?= $n['visibility'] === 'everyone' ? 'info' : ($n['visibility'] === 'managers' ? 'warning' : 'secondary') ?>"><i data-lucide="<?= $n['visibility'] === 'private' ? 'lock' : 'users' ?>" class="icon-sm"></i> <?= e(['everyone' => 'Everyone', 'managers' => 'Note managers', 'private' => 'Private'][$n['visibility']]) ?></span>
            <?= $n['status'] !== 'active' ? '<span class="badge rounded-pill badge-soft-secondary">Archived</span>' : '' ?>
            <span class="ms-auto d-flex gap-1"><?php if ($canNote && $notesSvc !== null && $notesSvc->canEdit($n, auth_user())): ?>
                <a class="btn btn-sm btn-light" href="<?= e($tabUrl('notes', ['edit' => $n['id']])) ?>">Edit</a>
                <form method="post" action="<?= e(route('ecommerce.platforms.notes.archive', ['id' => $pid, 'note' => $n['id']])) ?>" data-confirm="Archive this note?"><?= csrf_field() ?><button class="btn btn-sm btn-light text-danger" type="submit">Archive</button></form><?php endif; ?></span></div>
        <p class="small mb-1 mt-1"><?= nl2br(e($n['body'])) ?></p>
        <div class="small text-secondary"><?= e($n['created_by_name'] ?? '—') ?> · <?= e(format_datetime($n['created_at'])) ?><?= $n['updated_at'] !== $n['created_at'] ? ' · edited ' . e(time_ago($n['updated_at'])) : '' ?></div>
    </li><?php endforeach; ?></ul>
    <?php endif; ?>

<?php elseif ($tab === 'history'): ?>
    <?= $view->partial('ecommerce/_history', ['history' => $history, 'showPlatform' => false, 'seePartners' => ($fields['partner_mappings'] ?? null)?->canView() !== false]) ?>
    <?php if ($audit !== []): ?><h2 class="h6 mt-4">Audit log</h2><div class="table-responsive"><table class="table app-table table-sm mb-0 table-stack"><thead><tr><th>Action</th><th>By</th><th>When</th></tr></thead><tbody>
        <?php foreach ($audit as $a): ?><tr><td data-label="Action"><?= e($a['description']) ?></td><td data-label="By"><?= e($a['user_name'] ?? 'System') ?></td><td data-label="When" class="small text-nowrap"><?= e(format_datetime($a['created_at'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
<?php endif; ?>
            </div>
        </div>
    </div>
</div>
