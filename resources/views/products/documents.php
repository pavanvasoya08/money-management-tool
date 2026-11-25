<?php
/** @var list<array<string, mixed>> $rows @var array<string, mixed> $f @var array<string, string> $categories */
use App\Services\Files\SecureFileStore;
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Product documents</h1><p>Specifications, manuals, certificates, warranty and compliance documents across your products. Upload on each product's Images &amp; documents tab.</p></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('products.documents')) ?>">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Title, file or product" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($categories as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <div class="form-check ms-1"><input class="form-check-input" type="checkbox" id="d-exp" name="expiring" value="1" <?= $f['expiring'] === '1' ? 'checked' : '' ?>><label class="form-check-label" for="d-exp">Expired or expiring within 60 days</label></div>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
    </form>
    <?php if ($rows === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'file-text', 'text' => 'No documents found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Document</th><th>Product</th><th>Category</th><th>Valid until</th><th>Uploaded</th><th class="text-end"></th></tr></thead>
        <tbody><?php foreach ($rows as $d): ?><tr>
            <td data-label="Document"><b><?= e($d['title']) ?></b><span class="d-block small text-secondary"><?= e($d['original_name']) ?> · <?= e(SecureFileStore::formatSize((int) $d['size_bytes'])) ?></span></td>
            <td data-label="Product"><a href="<?= e(route('products.show', ['id' => $d['product_id'], 'tab' => 'media'])) ?>"><?= e($d['product_name']) ?></a></td>
            <td data-label="Category"><?= e($categories[$d['category']] ?? $d['category']) ?></td>
            <td data-label="Valid until"><?= $d['expiry_date'] ? e(format_date($d['expiry_date'])) . ($d['expiry_date'] < \App\Support\BusinessDate::today() ? ' <span class="badge badge-soft-danger">Expired</span>' : '') : '—' ?></td>
            <td data-label="Uploaded" class="small"><?= e($d['uploaded_by_name'] ?? '—') ?><span class="d-block text-secondary"><?= e(format_date($d['created_at'])) ?></span></td>
            <td class="text-end"><?php if (can('products.download_documents')): ?><a class="btn btn-sm btn-light" href="<?= e(route('products.documents.show', ['id' => $d['product_id'], 'doc' => $d['id']])) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
