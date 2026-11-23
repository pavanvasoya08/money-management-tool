<?php $tone = ['active' => 'success', 'inactive' => 'secondary', 'suspended' => 'warning', 'archived' => 'dark'][$status] ?? 'secondary'; ?>
<span class="badge rounded-pill badge-soft-<?= $tone ?>"><span class="dot"></span><?= e(\App\Services\Partners\PartnerService::STATUSES[$status] ?? $status) ?></span>
