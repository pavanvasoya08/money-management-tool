<?php
/** @var list<array<string, mixed>> $rows @var array<string, mixed> $f @var array<string, string> $categories */
use App\Services\Files\SecureFileStore;
use App\Support\BusinessDate;

$cats = can('suppliers.view_bank_details') ? $categories : array_diff_key($categories, ['bank_proof' => 1]);
$today = BusinessDate::today();
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Supplier documents</h1><p>Active documents of the suppliers you can see. Every view and download is logged.</p></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('suppliers.documents')) ?>" role="search">
        <div class="input-icon toolbar-search"><i data-lucide="search"></i><input class="form-control" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Title, file name or supplier" aria-label="Search"></div>
        <select class="form-select toolbar-select" name="category" aria-label="Category"><option value="">Any category</option><?php foreach ($cats as $k => $l): ?><option value="<?= e($k) ?>" <?= $f['category'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="expiring" aria-label="Validity"><option value="">Any validity</option><option value="1" <?= $f['expiring'] === '1' ? 'selected' : '' ?>>Expired or expiring in 60 days</option></select>
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f)): ?><a class="btn btn-link text-secondary" href="<?= e(route('suppliers.documents')) ?>">Clear</a><?php endif; ?>
    </form>
    <?php if ($rows === []): ?><div class="card-body"><?= $view->partial('partials/empty', ['icon' => 'file-text', 'text' => 'No documents found.']) ?></div><?php else: ?>
    <div class="table-responsive"><table class="table app-table mb-0 table-stack">
        <thead><tr><th>Document</th><th>Supplier</th><th>Category</th><th>Valid until</th><th>Uploaded</th><th class="text-end"></th></tr></thead>
        <tbody><?php foreach ($rows as $d): ?><tr>
            <td data-label="Document"><b><?= e($d['title']) ?></b><span class="d-block small text-secondary"><?= e($d['original_name']) ?> · <?= e(SecureFileStore::formatSize((int) $d['size_bytes'])) ?></span></td>
            <td data-label="Supplier"><a href="<?= e(route('suppliers.show', ['id' => $d['supplier_id'], 'tab' => 'documents'])) ?>"><?= e($d['supplier_name']) ?></a></td>
            <td data-label="Category"><?= e($categories[$d['category']] ?? $d['category']) ?></td>
            <td data-label="Valid until"><?= $d['expiry_date'] ? e(format_date($d['expiry_date'])) . ($d['expiry_date'] < $today ? ' <span class="badge badge-soft-danger">Expired</span>' : '') : '—' ?></td>
            <td data-label="Uploaded" class="small"><?= e($d['uploaded_by_name'] ?? '—') ?><span class="d-block text-secondary"><?= e(format_date(substr((string) $d['created_at'], 0, 10))) ?></span></td>
            <td class="text-end text-nowrap"><?php if (can('suppliers.download_documents')): ?><a class="btn btn-sm btn-light" href="<?= e(route('suppliers.documents.show', ['id' => $d['supplier_id'], 'doc' => $d['id']])) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></td>
        </tr><?php endforeach; ?></tbody>
    </table></div>
    <?php endif; ?>
</div>
