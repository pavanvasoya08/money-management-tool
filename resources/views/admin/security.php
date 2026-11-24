<div class="page-head"><div><h1>Security settings</h1><p>Authentication, session and access-control policy for the whole system.</p></div></div>
<form method="post" action="<?= e(route('admin.security.save')) ?>" novalidate data-loading class="form-narrow-lg"
      data-confirm="Save security settings? They apply to every user immediately." data-confirm-button="Save settings">
    <?= csrf_field() ?>
    <?php foreach (['security' => ['Sign-in & sessions', 'lock-keyhole'], 'access' => ['Access control', 'shield-check'], 'finance' => ['Partner capital', 'piggy-bank']] as $group => [$label, $icon]): ?>
    <div class="card app-card mb-3"><div class="card-header"><i data-lucide="<?= e($icon) ?>" class="icon-sm"></i> <?= e($label) ?></div><div class="card-body p-4"><div class="row g-3">
        <?php foreach ($definitions as $key => $def): if ($def['group'] !== $group) { continue; } $v = old($key, $values[$key]); ?>
            <?php if ($def['type'] === 'boolean'): ?>
                <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= $v ? 'checked' : '' ?>>
                    <label class="form-check-label" for="s-<?= e($key) ?>"><?= e($def['label']) ?></label><?php if (!empty($def['help'])): ?><div class="form-text"><?= e($def['help']) ?></div><?php endif; ?></div></div>
            <?php else: ?>
                <div class="col-md-6"><label class="form-label" for="s-<?= e($key) ?>"><?= e($def['label']) ?></label><input class="form-control<?= invalid($key) ?>" type="number" id="s-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($v) ?>"><?= $view->partial('partials/field-error', ['field' => $key]) ?></div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div></div></div>
    <?php endforeach; ?>
    <div class="form-actions-bar"><button class="btn btn-primary" type="submit"><span class="btn-label">Save security settings</span></button></div>
</form>
