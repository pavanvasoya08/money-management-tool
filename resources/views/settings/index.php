<?php
$groups = ['general' => ['General', 'sliders-horizontal']];
$timezones = DateTimeZone::listIdentifiers();
$formats = ['d M Y' => '23 Nov 2026', 'd/m/Y' => '23/11/2026', 'd-m-Y' => '23-11-2026', 'Y-m-d' => '2026-11-23', 'M d Y' => 'Nov 23 2026'];
?>
<div class="page-head">
    <div>
        <h1>Settings</h1>
        <p>System-wide configuration. Changes are recorded in the activity log.</p>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-8">
        <form method="post" action="<?= e(route('settings.update')) ?>" novalidate data-loading>
            <?= csrf_field() ?>
            <fieldset <?= $canEdit ? '' : 'disabled' ?>>
            <?php foreach ($groups as $group => [$label, $icon]): ?>
                <div class="card app-card mb-3">
                    <div class="card-header"><i data-lucide="<?= e($icon) ?>" class="icon-sm"></i> <?= e($label) ?></div>
                    <div class="card-body p-4">
                        <div class="row g-3">
                        <?php foreach ($definitions as $key => $def): if ($def['group'] !== $group) { continue; } $value = old($key, $values[$key]); ?>
                            <div class="col-md-6">
                                <label class="form-label" for="s-<?= e($key) ?>"><?= e($def['label']) ?></label>
                                <?php if ($key === 'timezone'): ?>
                                    <select class="form-select<?= invalid($key) ?>" id="s-<?= e($key) ?>" name="<?= e($key) ?>">
                                        <?php foreach ($timezones as $tz): ?><option value="<?= e($tz) ?>" <?= $tz === $value ? 'selected' : '' ?>><?= e($tz) ?></option><?php endforeach; ?>
                                    </select>
                                <?php elseif ($key === 'date_format'): ?>
                                    <select class="form-select<?= invalid($key) ?>" id="s-<?= e($key) ?>" name="<?= e($key) ?>">
                                        <?php foreach ($formats as $f => $example): ?><option value="<?= e($f) ?>" <?= $f === $value ? 'selected' : '' ?>><?= e($example) ?></option><?php endforeach; ?>
                                    </select>
                                <?php elseif ($def['type'] === 'integer'): ?>
                                    <input class="form-control<?= invalid($key) ?>" type="number" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>" inputmode="numeric">
                                <?php else: ?>
                                    <input class="form-control<?= invalid($key) ?>" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($value) ?>">
                                <?php endif; ?>
                                <?= $view->partial('partials/field-error', ['field' => $key]) ?>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </fieldset>
            <?php if ($isSuperAdmin): ?>
                <div class="alert alert-info small d-flex gap-2 align-items-center"><i data-lucide="shield" class="flex-shrink-0"></i>
                    <span>Security and access-control settings are managed in the <a href="<?= e(route('admin.security')) ?>">Super Admin Control Center → Security settings</a>.</span></div>
            <?php endif; ?>
            <?php if ($canEdit): ?>
                <div class="d-flex justify-content-end"><button class="btn btn-primary" type="submit"><i data-lucide="check" class="icon-sm"></i><span class="btn-label">Save settings</span></button></div>
            <?php else: ?>
                <p class="text-secondary small">You can view but not change settings.</p>
            <?php endif; ?>
        </form>
    </div>
    <div class="col-xl-4">
        <div class="card app-card mb-3">
            <div class="card-header"><i data-lucide="shield-check" class="icon-sm"></i> Roles &amp; starting permissions</div>
            <div class="card-body">
                <?php foreach ($roleGrants as $r): ?>
                    <div class="mb-3">
                        <div class="fw-semibold small mb-1"><?= e($r['name']) ?></div>
                        <?php if ($r['super']): ?>
                            <span class="badge text-bg-dark">All permissions</span>
                        <?php elseif ($r['permissions'] === []): ?>
                            <span class="small text-secondary">No permissions</span>
                        <?php else: ?>
                            <div class="d-flex flex-wrap gap-1"><?php foreach ($r['permissions'] as $p): ?><code class="perm-chip"><?= e($p) ?></code><?php endforeach; ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <p class="small text-secondary mb-0">Full role &amp; permission management (custom roles, per-user overrides, data and field-level access) is delivered in Phase 2.</p>
            </div>
        </div>
        <div class="card app-card">
            <div class="card-header"><i data-lucide="server" class="icon-sm"></i> System</div>
            <div class="card-body">
                <dl class="detail-list mb-0">
                    <?php foreach ($system as $k => $v): ?><dt><?= e($k) ?></dt><dd><?= e($v) ?></dd><?php endforeach; ?>
                </dl>
            </div>
        </div>
    </div>
</div>
