<?php
/** Product status pill. @var array<string, mixed> $p (status, archived_use_allowed) */
$tone = ['active' => 'success', 'draft' => 'secondary', 'pending_approval' => 'warning', 'inactive' => 'secondary', 'discontinued' => 'info', 'archived' => 'dark'][$p['status']] ?? 'secondary';
?>
<span class="badge rounded-pill badge-soft-<?= $tone ?>"><span class="dot"></span><?= e(\App\Services\Product\ProductService::STATUSES[$p['status']] ?? $p['status']) ?></span>
<?php if ($p['status'] === 'archived' && !empty($p['archived_use_allowed'])): ?><span class="badge rounded-pill badge-soft-warning" title="Explicitly allowed in new transactions">Use allowed</span><?php endif; ?>
