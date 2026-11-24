<?php /* Capital entry status as a coloured dot + label (reference: Paid / Pending / Failed). */
$map = ['draft' => 'neutral', 'pending_approval' => 'warning', 'approved' => 'info', 'rejected' => 'danger', 'posted' => 'success', 'reversed' => 'dark', 'cancelled' => 'neutral'];
?>
<span class="status-dot is-<?= e($map[$status] ?? 'neutral') ?> cap-status-<?= e($status) ?>"><?= e(\App\Services\Partners\CapitalService::STATUSES[$status] ?? $status) ?></span>
