<?php
/** GST masters and business rules. @var string $tab @var list<array<string, mixed>> $rows @var array<int, int> $usage @var array<string, array<string, mixed>> $definitions @var array<string, mixed> $values */
use App\Services\Gst\GstMasterService;

$tabs = array_map(static fn (array $m): string => $m[1], GstMasterService::MASTERS) + ['rules' => 'Business rules'];
$cb = static fn (string $name, bool $on, string $label, string $id): string => '<div class="form-check form-check-inline"><input type="hidden" name="' . $name . '" value="0"><input class="form-check-input" type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '">' . e($label) . '</label></div>';
$form = static function (string $tab, ?array $r, callable $cb): string {
    $p = $r === null ? 'new' : 'm' . (int) $r['id'];
    $v = static fn (string $k, string $d = ''): string => e((string) ($r[$k] ?? $d));
    $h = '<div class="row g-2 align-items-end">';
    $h .= '<div class="col-md-2"><label class="form-label small" for="' . $p . '-code">Code *</label><input class="form-control form-control-sm" id="' . $p . '-code" name="code" value="' . $v('code') . '" required maxlength="40"' . ($tab === 'states' ? ' inputmode="numeric" pattern="[0-9]{2}" placeholder="e.g. 24"' : ' placeholder="e.g. ecommerce"') . '></div>';
    $h .= '<div class="col-md-4"><label class="form-label small" for="' . $p . '-name">Name *</label><input class="form-control form-control-sm" id="' . $p . '-name" name="name" value="' . $v('name') . '" required maxlength="120"></div>';
    if ($tab === 'states') {
        $h .= '<div class="col-md-2"><label class="form-label small" for="' . $p . '-abbr">Abbreviation</label><input class="form-control form-control-sm" id="' . $p . '-abbr" name="abbreviation" value="' . $v('abbreviation') . '" maxlength="4"></div>';
    }
    if ($tab === 'types') {
        $basis = (string) ($r['id_basis'] ?? 'pan');
        $h .= '<div class="col-md-2"><label class="form-label small" for="' . $p . '-basis">GSTIN carries</label><select class="form-select form-select-sm" id="' . $p . '-basis" name="id_basis">';
        foreach (['pan' => 'PAN', 'tan' => 'TAN', 'other' => 'Other format'] as $k => $l) {
            $h .= '<option value="' . $k . '"' . ($basis === $k ? ' selected' : '') . '>' . $l . '</option>';
        }
        $h .= '</select></div><div class="col-md-1"><label class="form-label small" for="' . $p . '-14">14th char</label><input class="form-control form-control-sm text-uppercase" id="' . $p . '-14" name="fourteenth_char" value="' . $v('fourteenth_char') . '" maxlength="1" title="Leave empty if not fixed"></div>';
    }
    $h .= '<div class="col-md-' . ($tab === 'types' ? '3' : ($tab === 'states' ? '4' : '6')) . '"><label class="form-label small" for="' . $p . '-desc">' . ($tab === 'states' ? 'Notes' : 'Description') . '</label><input class="form-control form-control-sm" id="' . $p . '-desc" name="description" value="' . $v($tab === 'states' ? 'notes' : 'description') . '" maxlength="255"></div>';
    $h .= '<div class="col-md-2"><label class="form-label small" for="' . $p . '-sort">Order</label><input class="form-control form-control-sm" type="number" id="' . $p . '-sort" name="sort_order" value="' . $v('sort_order', '0') . '" min="0"></div>';
    $h .= '<div class="col-md-10">' . $cb('is_active', $r === null || (int) $r['is_active'] === 1, 'Active', $p . '-act');
    if ($tab === 'states') {
        $h .= $cb('is_union_territory', (int) ($r['is_union_territory'] ?? 0) === 1, 'Union territory', $p . '-ut') . $cb('allows_registration', $r === null || (int) $r['allows_registration'] === 1, 'Valid as GSTIN state code', $p . '-reg');
    }
    if ($tab === 'types') {
        $h .= $cb('has_validity_period', (int) ($r['has_validity_period'] ?? 0) === 1, 'Limited validity period', $p . '-val');
    }
    if ($tab === 'relationships') {
        $h .= $cb('counts_as_owner', (int) ($r['counts_as_owner'] ?? 0) === 1, 'Counts as owner (single-owner rule)', $p . '-own');
    }
    return $h . '</div></div>';
};
?>
<div class="page-head"><div><h1>GST masters &amp; rules</h1><p>Configurable master data used by GST registrations. Values in use cannot change their code; deactivate them instead of removing.</p></div></div>
<div class="card app-card tabs-card">
    <ul class="nav app-tabs" role="tablist">
        <?php foreach ($tabs as $k => $l): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="<?= e(route('gst.masters', ['tab' => $k])) ?>"<?= $tab === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a></li><?php endforeach; ?>
    </ul>
    <div class="tab-body">
        <?php if ($tab === 'rules'): ?>
            <form method="post" action="<?= e(route('gst.masters.rules')) ?>" class="form-narrow" data-loading><?= csrf_field() ?>
                <?php foreach ($definitions as $key => $def): ?>
                    <div class="mb-3">
                        <?php if ($def['type'] === 'boolean'): ?>
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="r-<?= e($key) ?>" name="<?= e($key) ?>" value="1" <?= !empty($values[$key]) ? 'checked' : '' ?>><label class="form-check-label fw-semibold" for="r-<?= e($key) ?>"><?= e($def['label']) ?></label></div>
                        <?php else: ?>
                            <label class="form-label fw-semibold" for="r-<?= e($key) ?>"><?= e($def['label']) ?></label><input class="form-control<?= invalid($key) ?>" type="number" id="r-<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e((string) old($key, (string) $values[$key])) ?>" min="1" max="365"><?= $view->partial('partials/field-error', ['field' => $key]) ?>
                        <?php endif; ?>
                        <?php if (!empty($def['help'])): ?><div class="form-text"><?= e($def['help']) ?></div><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <p class="small text-secondary">Approval requirements for new registrations, key-detail changes and cancellations are configured in <b>Control Center → Approval workflows</b>.</p>
                <button class="btn btn-primary" type="submit">Save rules</button>
            </form>
        <?php else: ?>
            <details class="card app-card mb-4 bg-fill add-panel" <?= errors() !== [] ? 'open' : '' ?>>
                <summary class="card-body py-3 fw-semibold"><i data-lucide="plus" class="icon-sm"></i> Add to <?= e(strtolower($tabs[$tab])) ?></summary>
                <form class="card-body pt-0" method="post" action="<?= e(route('gst.masters.store', ['master' => $tab])) ?>" data-loading><?= csrf_field() ?>
                    <?= $form($tab, null, $cb) ?>
                    <?php foreach (['code', 'name'] as $f): ?><?= $view->partial('partials/field-error', ['field' => $f]) ?><?php endforeach; ?>
                    <button class="btn btn-primary btn-sm mt-2" type="submit">Add</button>
                </form>
            </details>
            <div class="table-responsive"><table class="table app-table mb-0 table-stack">
                <thead><tr><th>Code</th><th>Name</th><th><?= $tab === 'states' ? 'Details' : 'Description' ?></th><th class="text-end">Used by</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
                <tbody><?php foreach ($rows as $r): ?><tr class="<?= (int) $r['is_active'] ? '' : 'text-secondary' ?>">
                    <td data-label="Code" class="text-mono"><?= e($r['code']) ?></td>
                    <td data-label="Name" class="fw-semibold"><?= e($r['name']) ?></td>
                    <td data-label="Details" class="small"><?php
                        if ($tab === 'states') { echo e(implode(' · ', array_filter([$r['abbreviation'], (int) $r['is_union_territory'] ? 'Union territory' : 'State', (int) $r['allows_registration'] ? null : 'Not a GSTIN code', $r['notes']]))); }
                        elseif ($tab === 'types') { echo e(($r['description'] ?? '') . ' · GSTIN carries ' . strtoupper((string) $r['id_basis']) . ($r['fourteenth_char'] ? ', 14th char ' . $r['fourteenth_char'] : '') . ((int) $r['has_validity_period'] ? ' · limited validity' : '')); }
                        elseif ($tab === 'relationships') { echo e(($r['description'] ?? '') . ((int) $r['counts_as_owner'] ? ' · counts as owner' : '')); }
                        else { echo e($r['description'] ?? ''); } ?></td>
                    <td data-label="Used by" class="text-end"><?= (int) ($usage[(int) $r['id']] ?? 0) ?></td>
                    <td data-label="Status"><span class="badge rounded-pill badge-soft-<?= (int) $r['is_active'] ? 'success' : 'secondary' ?>"><?= (int) $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-end"><details class="inline-edit"><summary class="btn btn-sm btn-light">Edit</summary>
                        <form method="post" action="<?= e(route('gst.masters.update', ['master' => $tab, 'id' => $r['id']])) ?>" class="inline-edit-body inline-edit-wide text-start" data-loading><?= csrf_field() ?>
                            <?= $form($tab, $r, $cb) ?><button class="btn btn-primary btn-sm mt-2" type="submit">Save</button></form></details></td>
                </tr><?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>
