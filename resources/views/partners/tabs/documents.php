<?php $pid = (int) $raw['id']; ?>
<div class="row g-3">
    <div class="<?= can('partners.upload_documents') && $raw['status'] !== 'archived' ? 'col-xl-8' : 'col-12' ?>">
        <div class="card app-card"><div class="card-header">Documents</div>
        <?php if ($documents === []): ?>
            <?= $view->partial('partials/empty', ['icon' => 'folder-lock', 'text' => 'No documents you can see.']) ?>
        <?php else: ?>
        <div class="table-responsive"><table class="table app-table mb-0 table-stack">
            <thead><tr><th>Document</th><th>Type</th><th>Linked entry</th><th>Uploaded</th><th></th></tr></thead>
            <tbody><?php foreach ($documents as $d): ?><tr>
                <td data-label="Document"><span class="fw-semibold"><?= e($d['title']) ?></span><div class="small text-secondary"><?= e($d['original_name']) ?> · <?= e(\App\Services\Partners\DocumentService::formatSize((int) $d['size_bytes'])) ?></div></td>
                <td data-label="Type" class="small"><?= e(\App\Services\Partners\DocumentService::CATEGORIES[$d['category']] ?? $d['category']) ?></td>
                <td data-label="Entry" class="small"><?= e($d['transaction_no'] ?? '—') ?></td>
                <td data-label="Uploaded" class="small"><?= e(format_datetime($d['created_at'])) ?><div class="text-secondary"><?= e($d['uploaded_by_name']) ?></div></td>
                <td class="text-end text-nowrap"><a class="btn btn-sm btn-light" href="<?= e(route('partners.documents.download', ['id' => $pid, 'doc' => $d['id']])) ?>"><i data-lucide="download" class="icon-sm"></i>Download</a>
                    <?php if (can('partners.delete_documents')): ?><form class="d-inline" method="post" action="<?= e(route('partners.documents.remove', ['id' => $pid, 'doc' => $d['id']])) ?>" data-confirm="Remove this document? It stays in the audit trail." data-confirm-button="Remove" data-confirm-variant="danger"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit" aria-label="Remove"><i data-lucide="trash-2" class="icon-sm"></i></button></form><?php endif; ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
        <div class="card-footer bg-white small text-secondary"><i data-lucide="lock" class="icon-sm"></i> Stored privately outside the website folder. Every download is permission-checked and logged.</div>
        </div>
    </div>
    <?php if (can('partners.upload_documents') && $raw['status'] !== 'archived'): ?>
    <div class="col-xl-4"><form class="card app-card" method="post" action="<?= e(route('partners.documents.store', ['id' => $pid])) ?>" enctype="multipart/form-data" novalidate data-loading>
        <div class="card-header">Upload document</div><div class="card-body"><?= csrf_field() ?>
            <label class="form-label small" for="doc-cat">Type</label><select class="form-select form-select-sm mb-2<?= invalid('category') ?>" id="doc-cat" name="category" required><?php foreach ($categories as $k => $l): ?><option value="<?= e($k) ?>" <?= old('category') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
            <label class="form-label small" for="doc-title">Title (optional)</label><input class="form-control form-control-sm mb-2" id="doc-title" name="title" maxlength="150" value="<?= e(old('title')) ?>">
            <label class="form-label small" for="doc-file">File</label><input class="form-control form-control-sm<?= invalid('document') ?>" type="file" id="doc-file" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx" required><?= $view->partial('partials/field-error', ['field' => 'document']) ?>
            <div class="form-text mb-3">PDF, JPG, PNG, WEBP, DOCX or XLSX up to 5 MB.</div>
            <button class="btn btn-primary btn-sm" type="submit"><span class="btn-label">Upload</span></button>
        </div></form></div>
    <?php endif; ?>
</div>
