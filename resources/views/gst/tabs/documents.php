<?php
/** Registration documents. @var list<array<string, mixed>> $documents @var array<string, string> $docTypes @var bool $showArchived */
use App\Services\Gst\GstDocumentService;
use App\Services\Files\SecureFileStore;

$canManage = can('gst_registrations.manage_documents') && $raw['is_archived'] !== 1;
$today = \App\Support\BusinessDate::today();
?>
<p class="text-secondary small">Certificates and supporting documents. Files are stored privately and every view or download is permission-checked and logged. Allowed: PDF, JPG, PNG, WEBP, DOCX, XLSX up to 5 MB.</p>
<?php if ($canManage): ?>
<form class="card app-card mb-4 bg-fill" method="post" action="<?= e(route('gst.documents.upload', ['id' => $raw['id']])) ?>" enctype="multipart/form-data" data-loading>
    <div class="card-body row g-2 align-items-end"><?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label small" for="d-type">Document type <span class="text-danger">*</span></label>
            <select class="form-select<?= invalid('document_type') ?>" id="d-type" name="document_type" required><?php foreach ($docTypes as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label small" for="d-title">Title</label><input class="form-control" id="d-title" name="title" maxlength="150" placeholder="Defaults to the document type"></div>
        <div class="col-md-4"><label class="form-label small" for="d-exp">Expiry date</label><input class="form-control<?= invalid('expiry_date') ?>" type="date" id="d-exp" name="expiry_date"></div>
        <div class="col-md-6"><label class="form-label small" for="d-file">File <span class="text-danger">*</span></label><input class="form-control<?= invalid('document') ?>" type="file" id="d-file" name="document" required accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx"><?= $view->partial('partials/field-error', ['field' => 'document']) ?></div>
        <div class="col-md-4"><label class="form-label small" for="d-desc">Description</label><input class="form-control" id="d-desc" name="description" maxlength="500"></div>
        <div class="col-md-2"><button class="btn btn-primary w-100" type="submit"><span class="btn-label">Upload</span></button></div>
    </div>
</form>
<?php endif; ?>
<div class="d-flex justify-content-end mb-2">
    <a class="small" href="<?= e(route('gst.show', ['id' => $raw['id'], 'tab' => 'documents'] + ($showArchived ? [] : ['archived' => 1]))) ?>"><?= $showArchived ? 'Hide archived documents' : 'Show archived documents (history)' ?></a>
</div>
<?php if ($documents === []): ?>
    <?= $view->partial('partials/empty', ['icon' => 'folder', 'text' => 'No documents yet.' . ($canManage ? ' Upload the registration certificate first.' : '')]) ?>
<?php else: ?>
<div class="table-responsive"><table class="table app-table mb-0 table-stack">
    <thead><tr><th>Document</th><th>Uploaded</th><th>Expiry</th><th class="text-end">Actions</th></tr></thead>
    <tbody><?php foreach ($documents as $d): $expired = $d['expiry_date'] !== null && $d['expiry_date'] < $today; ?>
        <tr class="<?= $d['status'] !== 'active' ? 'text-secondary' : '' ?>">
            <td data-label="Document"><span class="fw-semibold"><?= e($d['title']) ?></span><?php if ($d['status'] !== 'active'): ?> <span class="badge rounded-pill badge-soft-secondary">Archived</span><?php endif; ?>
                <span class="d-block small"><?= e($docTypes[$d['document_type']] ?? $d['document_type']) ?></span><span class="d-block small text-secondary"><?= e($d['original_name']) ?> · <?= e(SecureFileStore::formatSize((int) $d['size_bytes'])) ?><?= $d['description'] ? ' · ' . e($d['description']) : '' ?></span><?php if ($d['status'] !== 'active' && $d['archive_reason']): ?><span class="d-block small text-secondary">Archived: <?= e($d['archive_reason']) ?></span><?php endif; ?></td>
            <td data-label="Uploaded" class="small text-nowrap"><?= e(format_datetime($d['created_at'])) ?><span class="d-block text-secondary"><?= e($d['uploaded_by_name'] ?? '—') ?></span></td>
            <td data-label="Expiry" class="small text-nowrap"><?= $d['expiry_date'] ? '<span class="' . ($expired ? 'text-danger fw-semibold' : '') . '">' . e(format_date($d['expiry_date'])) . ($expired ? ' · expired' : '') . '</span>' : '—' ?></td>
            <td class="text-end text-nowrap">
                <?php if (GstDocumentService::previewable($d)): ?><a class="btn btn-sm btn-light" href="<?= e(route('gst.documents.preview', ['doc' => $d['id']])) ?>" target="_blank" rel="noopener">Preview</a><?php endif; ?>
                <a class="btn btn-sm btn-light" href="<?= e(route('gst.documents.download', ['doc' => $d['id']])) ?>" aria-label="Download"><i data-lucide="download" class="icon-sm"></i></a>
                <?php if ($canManage && $d['status'] === 'active'): ?>
                <details class="inline-edit d-inline-block"><summary class="btn btn-sm btn-light">Archive</summary>
                    <form method="post" action="<?= e(route('gst.documents.archive', ['doc' => $d['id']])) ?>" class="inline-edit-body text-start"><?= csrf_field() ?>
                        <input class="form-control form-control-sm mb-2" name="reason" placeholder="Reason (required), e.g. replaced by amended certificate" required maxlength="255" aria-label="Reason">
                        <button class="btn btn-sm btn-outline-danger w-100" type="submit">Archive document</button></form></details>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?></tbody>
</table></div>
<?php endif; ?>
