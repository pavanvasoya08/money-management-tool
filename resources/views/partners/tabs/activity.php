<div class="card app-card"><div class="card-header">Activity history <span class="small text-secondary fw-normal">· profile, capital entries and documents</span></div>
<?php if ($activity === []): ?><?= $view->partial('partials/empty', ['icon' => 'scroll-text', 'text' => 'No activity yet.']) ?><?php else: ?>
<ul class="activity-list"><?php foreach ($activity as $a): ?>
    <li><span class="activity-icon tone-slate"><i data-lucide="history"></i></span><div class="flex-grow-1 min-w-0"><div><?= e($a['description']) ?></div><div class="small text-secondary"><code><?= e($a['action']) ?></code> · <?= e($a['user_name'] ?? 'System') ?><?php if (\App\Core\App::gate()->isSuperAdmin(auth_user())): ?> · <a href="<?= e(route('admin.audit.show', ['id' => $a['id']])) ?>">details</a><?php endif; ?></div></div><span class="small text-secondary text-nowrap"><?= e(format_datetime($a['created_at'])) ?></span></li>
<?php endforeach; ?></ul>
<?php endif; ?></div>
