<div class="page-head"><div><h1>Approval workflows</h1><p>Configure multi-level, amount-based approvals. Financial modules submit to these workflows as they are released — no transactions are created here.</p></div>
    <div class="page-actions"><a class="btn btn-light" href="<?= e(route('admin.workflows.requests')) ?>"><i data-lucide="inbox" class="icon-sm"></i>All requests</a></div></div>
<div class="row g-3">
<?php foreach ($workflows as $w): ?>
    <div class="col-md-6 col-xxl-4"><a class="card app-card h-100 text-decoration-none text-reset wf-card" href="<?= e(route('admin.workflows.show', ['id' => $w['id']])) ?>"><div class="card-body">
        <div class="d-flex justify-content-between align-items-start mb-2"><span class="fw-semibold"><?= e($w['name']) ?></span><?= $w['is_active'] ? '<span class="badge badge-soft-success">Active</span>' : '<span class="badge badge-soft-secondary">Inactive</span>' ?></div>
        <p class="small text-secondary mb-2"><?= e($w['description'] ?? '') ?></p>
        <div class="small"><span class="text-secondary">Module:</span> <?= e($w['module_name']) ?> · <?= (int) $w['step_count'] ?> step(s) · <?= (int) $w['pending'] ?> pending</div>
    </div></a></div>
<?php endforeach; ?>
</div>
