<?php
/**
 * @var array<string, mixed> $p @var list<array<string, mixed>> $images @var list<array<string, mixed>> $documents @var array<string, string> $docCategories
 * @var list<array<string, mixed>> $variantRows @var bool $showArchived
 */
use App\Services\Files\SecureFileStore;

$canManage = $p['status'] !== 'archived' && can('products.manage_documents');
$pid = (int) $p['id'];
?>
<div class="d-flex justify-content-between align-items-center mb-2"><h2 class="card-section-title mb-0">Images</h2>
    <a class="small" href="<?= e(route('products.show', ['id' => $pid, 'tab' => 'media', 'archived' => $showArchived ? 0 : 1])) ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a></div>
<?php if ($canManage): ?>
<form class="card app-card mb-3 bg-fill" method="post" enctype="multipart/form-data" action="<?= e(route('products.images.store', ['id' => $pid])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-5"><label class="form-label small" for="img-file">Image (JPG, PNG or WebP, up to <?= e(SecureFileStore::formatSize(SecureFileStore::MAX_BYTES)) ?>)</label><input class="form-control<?= invalid('image') ?>" type="file" id="img-file" name="image" accept=".jpg,.jpeg,.png,.webp" required><?= $view->partial('partials/field-error', ['field' => 'image']) ?></div>
        <div class="col-md-3"><label class="form-label small" for="img-variant">For variant</label><select class="form-select" id="img-variant" name="variant_id"><option value="">Whole product</option><?php foreach ($variantRows as $v): if ($v['status'] === 'archived') { continue; } ?><option value="<?= (int) $v['id'] ?>"><?= e($v['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small" for="img-caption">Caption</label><input class="form-control" id="img-caption" name="caption" maxlength="255"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Upload</button></div>
    </div>
</form>
<?php endif; ?>
<?php if ($images === []): ?><p class="text-secondary small mb-4">No images yet.</p><?php else: ?>
<div class="product-gallery mb-4">
    <?php foreach ($images as $i): ?>
        <figure class="product-gallery-item<?= (int) $i['is_primary'] ? ' is-primary' : '' ?><?= $i['status'] !== 'active' ? ' is-archived' : '' ?>">
            <a href="<?= e(route('products.images.show', ['id' => $pid, 'image' => $i['id']])) ?>" target="_blank" rel="noopener"><img src="<?= e(route('products.images.show', ['id' => $pid, 'image' => $i['id']])) ?>" alt="<?= e($i['caption'] ?? $i['original_name']) ?>" loading="lazy"></a>
            <figcaption><?php if ((int) $i['is_primary']): ?><span class="badge badge-soft-primary">Primary</span><?php endif; ?><?php if ($i['variant_name']): ?><span class="badge badge-soft-secondary"><?= e($i['variant_name']) ?></span><?php endif; ?>
                <span class="d-block small text-truncate" title="<?= e($i['original_name']) ?>"><?= e($i['caption'] ?? $i['original_name']) ?></span>
                <span class="d-block small text-secondary"><?= (int) $i['width'] ?>×<?= (int) $i['height'] ?> · <?= e(SecureFileStore::formatSize((int) $i['size_bytes'])) ?></span>
                <?php if ($canManage && $i['status'] === 'active'): ?><div class="d-flex gap-1 mt-1">
                    <?php if (!(int) $i['is_primary']): ?><form method="post" action="<?= e(route('products.images.primary', ['id' => $pid, 'image' => $i['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit">Make primary</button></form><?php endif; ?>
                    <details class="inline-edit"><summary class="btn btn-sm btn-light">Archive</summary><form method="post" action="<?= e(route('products.images.archive', ['id' => $pid, 'image' => $i['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                        <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive</button></form></details></div>
                <?php elseif ($i['status'] !== 'active'): ?><span class="d-block small text-secondary">Archived: <?= e($i['archive_reason'] ?? '') ?></span><?php endif; ?>
            </figcaption>
        </figure>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 class="card-section-title">Documents</h2>
<?php if (!can('products.download_documents') && !can('products.manage_documents')): ?><p class="text-secondary small">You do not have access to product documents.</p><?php else: ?>
<?php if ($canManage): ?>
<form class="card app-card mb-3 bg-fill" method="post" enctype="multipart/form-data" action="<?= e(route('products.documents.store', ['id' => $pid])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small" for="doc-file">File (PDF, image, Word or Excel, up to <?= e(SecureFileStore::formatSize(SecureFileStore::MAX_BYTES)) ?>)</label><input class="form-control<?= invalid('document') ?>" type="file" id="doc-file" name="document" required><?= $view->partial('partials/field-error', ['field' => 'document']) ?></div>
        <div class="col-md-3"><label class="form-label small" for="doc-cat">Category</label><select class="form-select<?= invalid('category') ?>" id="doc-cat" name="category" required><?php foreach ($docCategories as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small" for="doc-title">Title</label><input class="form-control" id="doc-title" name="title" maxlength="150"></div>
        <div class="col-md-2"><label class="form-label small" for="doc-exp">Valid until</label><input class="form-control" type="date" id="doc-exp" name="expiry_date"></div>
        <div class="col-md-10"><input class="form-control" name="description" maxlength="500" placeholder="Description (optional)" aria-label="Description"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit">Upload</button></div>
    </div>
</form>
<?php endif; ?>
<?php if ($documents === []): ?><?= $view->partial('partials/empty', ['icon' => 'file-text', 'text' => 'No documents yet.']) ?><?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Document</th><th>Category</th><th>Valid until</th><th>Uploaded</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($documents as $d): ?><tr class="<?= $d['status'] !== 'active' ? 'text-secondary' : '' ?>">
        <td data-label="Document"><b><?= e($d['title']) ?></b><span class="d-block small text-secondary"><?= e($d['original_name']) ?> · <?= e(SecureFileStore::formatSize((int) $d['size_bytes'])) ?></span></td>
        <td data-label="Category"><?= e($docCategories[$d['category']] ?? $d['category']) ?></td>
        <td data-label="Valid until"><?= $d['expiry_date'] ? e(format_date($d['expiry_date'])) : '—' ?></td>
        <td data-label="Uploaded" class="small"><?= e($d['uploaded_by_name'] ?? '—') ?><span class="d-block text-secondary"><?= e(format_datetime($d['created_at'])) ?></span></td>
        <td class="text-end text-nowrap"><?php if (can('products.download_documents')): ?><a class="btn btn-sm btn-light" href="<?= e(route('products.documents.show', ['id' => $pid, 'doc' => $d['id']])) ?>" target="_blank" rel="noopener">View</a>
            <a class="btn btn-sm btn-light" href="<?= e(route('products.documents.show', ['id' => $pid, 'doc' => $d['id'], 'download' => 1])) ?>">Download</a><?php endif; ?>
            <?php if ($canManage && $d['status'] === 'active'): ?><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Archive</summary><form method="post" action="<?= e(route('products.documents.archive', ['id' => $pid, 'doc' => $d['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive</button></form></details><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
<?php endif; ?>
