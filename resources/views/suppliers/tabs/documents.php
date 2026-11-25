<?php
/** @var array<string, mixed> $s @var list<array<string, mixed>> $documents @var array<string, string> $docCategories @var bool $showArchived */
use App\Services\Files\SecureFileStore;

$sid = (int) $s['id'];
$canManage = $s['status'] !== 'archived' && can('suppliers.manage_documents');
$categories = can('suppliers.view_bank_details') ? $docCategories : array_diff_key($docCategories, ['bank_proof' => 1]);
?>
<div class="d-flex justify-content-between align-items-center mb-2"><p class="text-secondary small mb-0">GST certificate, PAN, registration proof, agreements, bank proofs and other documents. Stored privately; every view and download is logged.<?= can('suppliers.view_bank_details') ? '' : ' Bank proofs are not shown to you.' ?></p>
    <a class="small text-nowrap ms-2" href="<?= e(route('suppliers.show', ['id' => $sid, 'tab' => 'documents', 'archived' => $showArchived ? 0 : 1])) ?>"><?= $showArchived ? 'Hide archived' : 'Show archived' ?></a></div>
<?php if ($canManage): ?>
<form class="card app-card mb-3 bg-fill" method="post" enctype="multipart/form-data" action="<?= e(route('suppliers.documents.store', ['id' => $sid])) ?>" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small" for="sd-file">File (PDF, image, Word or Excel, up to <?= e(SecureFileStore::formatSize(SecureFileStore::MAX_BYTES)) ?>)</label><input class="form-control<?= invalid('document') ?>" type="file" id="sd-file" name="document" required><?= $view->partial('partials/field-error', ['field' => 'document']) ?></div>
        <div class="col-md-3"><label class="form-label small" for="sd-cat">Category</label><select class="form-select" id="sd-cat" name="category"><?php foreach ($categories as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small" for="sd-title">Title</label><input class="form-control" id="sd-title" name="title" maxlength="150"></div>
        <div class="col-md-2"><label class="form-label small" for="sd-exp">Valid until</label><input class="form-control" type="date" id="sd-exp" name="expiry_date"></div>
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
        <td data-label="Valid until"><?= $d['expiry_date'] ? e(format_date($d['expiry_date'])) . ($d['expiry_date'] < \App\Support\BusinessDate::today() ? ' <span class="badge badge-soft-danger">Expired</span>' : '') : '—' ?></td>
        <td data-label="Uploaded" class="small"><?= e($d['uploaded_by_name'] ?? '—') ?><span class="d-block text-secondary"><?= e(format_datetime($d['created_at'])) ?></span></td>
        <td class="text-end text-nowrap"><?php if (can('suppliers.download_documents')): ?><a class="btn btn-sm btn-light" href="<?= e(route('suppliers.documents.show', ['id' => $sid, 'doc' => $d['id']])) ?>" target="_blank" rel="noopener">View</a>
            <a class="btn btn-sm btn-light" href="<?= e(route('suppliers.documents.show', ['id' => $sid, 'doc' => $d['id'], 'download' => 1])) ?>">Download</a><?php endif; ?>
            <?php if ($canManage && $d['status'] === 'active'): ?><details class="inline-edit d-inline-block text-start"><summary class="btn btn-sm btn-light">Archive</summary><form method="post" action="<?= e(route('suppliers.documents.archive', ['id' => $sid, 'doc' => $d['id']])) ?>" class="inline-edit-body"><?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" name="reason" required maxlength="255" placeholder="Reason (required)" aria-label="Reason"><button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive</button></form></details><?php endif; ?></td>
    </tr><?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
