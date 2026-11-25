<?php
/** @var array<string, mixed> $s @var array<string, mixed> $fields */
$row = static function (string $label, string $field, ?string $v, string $type = '') use ($fields): string {
    if (isset($fields[$field]) && !$fields[$field]->canView()) {
        return '<div><dt>' . e($label) . '</dt><dd class="text-secondary">Hidden</dd></div>';
    }
    if ($v === null || $v === '') {
        return '<div><dt>' . e($label) . '</dt><dd>—</dd></div>';
    }
    if (isset($fields[$field]) && !$fields[$field]->showsRealValue()) {
        return '<div><dt>' . e($label) . '</dt><dd>' . e(\App\Services\Authorization\FieldPolicy::mask($v, \App\Core\App::fields()->maskType('suppliers', $field))) . '</dd></div>';
    }
    $html = match ($type) { 'mail' => '<a href="mailto:' . e($v) . '">' . e($v) . '</a>', 'tel' => '<a href="tel:' . e($v) . '">' . e($v) . '</a>', default => e($v) };
    return '<div><dt>' . e($label) . '</dt><dd>' . $html . '</dd></div>';
};
?>
<div class="d-flex justify-content-between align-items-start mb-2"><h2 class="card-section-title mb-0">Contact information</h2>
    <?php if ($s['status'] !== 'archived' && can('suppliers.edit')): ?><a class="btn btn-sm btn-light" href="<?= e(route('suppliers.edit', ['id' => $s['id']])) ?>"><i data-lucide="pencil" class="icon-sm"></i>Edit</a><?php endif; ?></div>
<dl class="detail-grid mb-3">
    <?= $row('Contact person', 'contact_person', $s['contact_person']) ?><?= $row('Mobile', 'contact_phone', $s['mobile'], 'tel') ?><?= $row('Email', 'contact_email', $s['email'], 'mail') ?>
    <div><dt>Website</dt><dd><?= $s['website'] ? '<a href="' . e($s['website']) . '" target="_blank" rel="noopener noreferrer">' . e($s['website']) . '</a>' : '—' ?></dd></div>
</dl>
<p class="small text-secondary mb-0">Addresses are on the <a href="<?= e(route('suppliers.show', ['id' => $s['id'], 'tab' => 'addresses'])) ?>">Addresses</a> tab.</p>
