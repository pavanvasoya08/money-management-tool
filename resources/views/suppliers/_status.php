<?php
/** Supplier status pill. @var array<string, mixed> $s */
$tone = ['active' => 'success', 'draft' => 'secondary', 'pending_approval' => 'warning', 'inactive' => 'secondary', 'blocked' => 'danger', 'archived' => 'dark'][$s['status']] ?? 'secondary';
?>
<span class="badge rounded-pill badge-soft-<?= $tone ?>"><span class="dot"></span><?= e(\App\Services\Supplier\SupplierService::STATUSES[$s['status']] ?? $s['status']) ?></span>
