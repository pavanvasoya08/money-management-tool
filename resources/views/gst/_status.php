<?php
/** GST registration status pill. @var string $status @var bool|null $archived @var string|null $approval */
$tone = ['active' => 'success', 'pending_verification' => 'info', 'inactive' => 'secondary', 'suspended' => 'warning', 'cancelled' => 'danger'][$status] ?? 'secondary';
?>
<span class="badge rounded-pill badge-soft-<?= $tone ?>"><span class="dot"></span><?= e(\App\Services\Gst\GstRegistrationService::STATUSES[$status] ?? $status) ?></span>
<?php if (!empty($archived)): ?><span class="badge rounded-pill badge-soft-dark">Archived</span><?php endif; ?>
<?php if (($approval ?? 'none') === 'pending'): ?><span class="badge rounded-pill badge-soft-warning" title="The creation request is waiting for approval">Awaiting approval</span><?php elseif (($approval ?? 'none') === 'rejected'): ?><span class="badge rounded-pill badge-soft-danger" title="The creation request was rejected">Rejected</span><?php endif; ?>
