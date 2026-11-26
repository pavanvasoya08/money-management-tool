<?php
/** Add / edit a platform. @var array<string, mixed>|null $p @var list<array<string, mixed>> $categories @var array<string, mixed> $fields */
use App\Support\EcommerceView;
use App\Support\Locale;

$editing = $p !== null;
$val = static fn (string $k, mixed $default = ''): string => (string) old($k, $p[$k] ?? $default);
$req = '<span class="text-danger">*</span>';
$see = static fn (string $f): bool => !isset($fields[$f]) || $fields[$f]->canView();
$ro = static fn (string $f): string => !isset($fields[$f]) || $fields[$f]->canEdit() ? '' : ' disabled';
$err = static fn (string $f): string => $view->partial('partials/field-error', ['field' => $f]);
$currencies = $editing ? Locale::codes((string) $p['currencies']) : array_keys(Locale::CURRENCIES);
?>
<div class="page-head"><div><h1><?= $editing ? 'Edit platform' : 'Add platform' ?></h1>
    <p><?= $editing ? e($p['platform_code'] . ' · ' . $p['name']) : 'A marketplace, online store, social channel, B2B portal, your own website or an offline channel the business sells through. New platforms start as “Under configuration”.' ?></p></div></div>
<form method="post" action="<?= e($editing ? route('ecommerce.platforms.update', ['id' => $p['id']]) : route('ecommerce.platforms.store')) ?>" enctype="multipart/form-data" data-loading><?= csrf_field() ?>
<div class="row g-4"><div class="col-xl-8">
    <div class="card app-card mb-4"><div class="card-header">Platform</div><div class="card-body row g-3">
        <div class="col-md-6"><label class="form-label" for="name">Platform name <?= $req ?></label><input class="form-control<?= invalid('name') ?>" id="name" name="name" value="<?= e($val('name')) ?>" maxlength="150" required placeholder="e.g. Amazon India"><?= $err('name') ?></div>
        <div class="col-md-3"><label class="form-label" for="platform_code">Platform code</label><input class="form-control text-uppercase text-mono<?= invalid('platform_code') ?>" id="platform_code" name="platform_code" value="<?= e($val('platform_code')) ?>" maxlength="30" placeholder="Automatic" pattern="[A-Za-z0-9][A-Za-z0-9_\-]{1,29}"><?= $err('platform_code') ?></div>
        <div class="col-md-3"><label class="form-label" for="category_id">Category <?= $req ?></label><select class="form-select<?= invalid('category_id') ?>" id="category_id" name="category_id" required><option value="">Choose…</option><?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $val('category_id') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select><?= $err('category_id') ?></div>
        <?php if ($see('website_url')): ?><div class="col-md-6"><label class="form-label" for="website_url">Website</label><input class="form-control<?= invalid('website_url') ?>" type="url" id="website_url" name="website_url" value="<?= e($val('website_url')) ?>" maxlength="255" placeholder="https://"<?= $ro('website_url') ?>><?= $err('website_url') ?></div><?php endif; ?>
        <div class="col-md-3"><label class="form-label" for="country">Country <?= $req ?></label><select class="form-select<?= invalid('country') ?>" id="country" name="country" required><?php foreach (Locale::COUNTRIES as $k => $l): ?><option value="<?= e($k) ?>" <?= $val('country', 'IN') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select><?= $err('country') ?></div>
        <div class="col-md-3"><label class="form-label" for="default_currency">Default currency <?= $req ?></label><select class="form-select<?= invalid('default_currency') ?>" id="default_currency" name="default_currency" required><?php foreach (Locale::CURRENCIES as $k => $l): if (!in_array($k, $currencies, true)) { continue; } ?><option value="<?= e($k) ?>" <?= $val('default_currency', 'INR') === $k ? 'selected' : '' ?>><?= e($k . ' — ' . $l) ?></option><?php endforeach; ?></select><?= $err('default_currency') ?>
            <?php if ($editing): ?><div class="form-text">Supported currencies are set on the Configuration tab.</div><?php endif; ?></div>
        <div class="col-12"><label class="form-label" for="description">Description</label><textarea class="form-control<?= invalid('description') ?>" id="description" name="description" rows="3" maxlength="5000"><?= e($val('description')) ?></textarea><?= $err('description') ?></div>
        <div class="col-md-3"><label class="form-label" for="display_order">Display order</label><input class="form-control<?= invalid('display_order') ?>" type="number" min="0" max="9999" id="display_order" name="display_order" value="<?= e($val('display_order', '0')) ?>"><?= $err('display_order') ?></div>
        <div class="col-md-9"><label class="form-label" for="logo">Logo</label>
            <div class="d-flex align-items-center gap-3"><?php if ($editing): ?><?= EcommerceView::logo($p) ?><?php endif; ?><input class="form-control<?= invalid('logo') ?>" type="file" id="logo" name="logo" accept=".png,.jpg,.jpeg,.webp,.gif"></div>
            <div class="form-text">PNG, JPG, WebP or GIF, up to 2 MB. Stored privately.</div><?= $err('logo') ?></div>
    </div></div>
    <?php if ($see('internal_notes')): ?>
    <div class="card app-card mb-4"><div class="card-header">Internal notes</div><div class="card-body"><textarea class="form-control" name="internal_notes" rows="3" maxlength="5000" aria-label="Internal notes"<?= $ro('internal_notes') ?>><?= e($val('internal_notes')) ?></textarea>
        <div class="form-text">Visible only to users allowed to see this field. Never put passwords, API keys or seller-portal logins here.</div></div></div>
    <?php endif; ?>
</div>
<div class="col-xl-4"><div class="card app-card"><div class="card-body small text-secondary">
    <p>After saving, open the <b>Configuration</b> tab to set the supported currencies, languages, time zone, formats and reference prefix, and record which capabilities the platform offers.</p>
    <p>Link the GST registrations, business activities and warehouses the platform is used with on their tabs. These are defaults and permitted scopes — not ownership.</p>
    <p class="mb-0">No seller accounts, logins or API keys are stored here. Those come with Phase 8B, in encrypted form.</p>
</div></div></div>
</div>
<div class="form-actions"><a class="btn btn-light" href="<?= e($editing ? route('ecommerce.platforms.show', ['id' => $p['id']]) : route('ecommerce.platforms.index')) ?>">Cancel</a><button class="btn btn-primary" type="submit"><i data-lucide="save" class="icon-sm"></i><?= $editing ? 'Save platform' : 'Add platform' ?></button></div>
</form>
