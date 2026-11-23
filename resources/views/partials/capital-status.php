<?php $map = ['draft' => 'secondary', 'pending_approval' => 'warning', 'approved' => 'info', 'rejected' => 'danger', 'posted' => 'success', 'reversed' => 'dark', 'cancelled' => 'secondary']; ?>
<span class="badge rounded-pill badge-soft-<?= e($map[$status] ?? 'secondary') ?> cap-status-<?= e($status) ?>"><?= e(\App\Services\Partners\CapitalService::STATUSES[$status] ?? $status) ?></span>
