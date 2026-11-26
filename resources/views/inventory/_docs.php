<?php
/**
 * Attachments of a warehouse or stock document.
 * @var list<array<string, mixed>> $documents @var array<string, string> $categories @var string $uploadUrl @var bool $canUpload @var string $backUrl
 */
?>
<?php if (can('inventory.manage_documents') && $canUpload): ?>
<form class="row g-2 align-items-end mb-3" method="post" action="<?= e($uploadUrl) ?>" enctype="multipart/form-data" data-loading><?= csrf_field() ?>
    <div class="col-md-4"><label class="form-label small" for="d-file">File <span class="text-danger">*</span></label><input class="form-control form-control-sm<?= invalid('file') ?>" type="file" id="d-file" name="file" required><?= $view->partial('partials/field-error', ['field' => 'file']) ?></div>
    <div class="col-md-3"><label class="form-label small" for="d-cat">Category</label><select class="form-select form-select-sm" id="d-cat" name="category"><?php foreach ($categories as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3"><label class="form-label small" for="d-title">Title</label><input class="form-control form-control-sm" id="d-title" name="title" maxlength="150"></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100" type="submit"><i data-lucide="upload" class="icon-sm"></i>Upload</button></div>
</form>
<?php endif; ?>
<?php if (!can('inventory.download_documents') && !can('inventory.manage_documents')): ?>
    <p class="text-secondary small mb-0">You do not have access to documents.</p>
<?php elseif ($documents === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'file-text', 'text' => 'No documents.']) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Document</th><th>Category</th><th>Size</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($documents as $d): ?><tr class="<?= $d['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <td data-label="Document"><b><?= e($d['title']) ?></b><span class="d-block small text-secondary"><?= e($d['original_name']) ?><?= $d['status'] !== 'active' ? ' · archived' : '' ?></span></td>
        <td data-label="Category"><?= e($categories[$d['category']] ?? $d['category']) ?></td>
        <td data-label="Size" class="small"><?= e(number_format(((int) $d['size_bytes']) / 1024, 1)) ?> KB</td>
        <td data-label="Uploaded" class="small"><?= e($d['uploaded_by_name'] ?? '—') ?><span class="d-block text-secondary"><?= e(format_datetime($d['created_at'])) ?></span></td>
        <td data-label="Actions" class="text-end text-nowrap">
            <?php if (can('inventory.download_documents')): ?><a class="btn btn-sm btn-light" href="<?= e(route('inventory.documents.show', ['doc' => $d['id'], 'inline' => 1])) ?>" target="_blank" rel="noopener">View</a>
                <a class="btn btn-sm btn-light" href="<?= e(route('inventory.documents.show', ['doc' => $d['id']])) ?>">Download</a><?php endif; ?>
            <?php if ($d['status'] === 'active' && can('inventory.manage_documents')): ?><form class="d-inline" method="post" action="<?= e(route('inventory.documents.archive', ['doc' => $d['id']])) ?>" data-confirm="Archive this document?"><?= csrf_field() ?>
                <input type="hidden" name="back" value="<?= e($backUrl) ?>"><input type="hidden" name="reason" value="Archived from the document list"><button class="btn btn-sm btn-light text-danger" type="submit">Archive</button></form><?php endif; ?>
        </td></tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
