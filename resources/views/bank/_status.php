<?php
/** Bank account status pill. @var array<string, mixed> $a (status, is_archived, is_central_funding) */
$tone = ['active' => 'success', 'draft' => 'secondary', 'pending_approval' => 'warning', 'inactive' => 'secondary', 'frozen' => 'info', 'closed' => 'danger'][$a['status']] ?? 'secondary';
?>
<span class="badge rounded-pill badge-soft-<?= $tone ?>"><span class="dot"></span><?= e(\App\Services\Bank\BankAccountService::STATUSES[$a['status']] ?? $a['status']) ?></span>
<?php if (!empty($a['is_central_funding']) && (int) $a['is_central_funding'] === 1): ?><span class="badge rounded-pill badge-soft-primary" title="Central joint funding account">Central</span><?php endif; ?>
<?php if (!empty($a['is_archived']) && (int) $a['is_archived'] === 1): ?><span class="badge rounded-pill badge-soft-dark">Archived</span><?php endif; ?>
