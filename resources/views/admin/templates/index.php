<div class="page-head"><div><h1>Permission templates</h1><p>Reusable sets of permissions and data scopes you can apply to roles or individual users.</p></div>
    <div class="page-actions"><a class="btn btn-primary" href="<?= e(route('admin.templates.create')) ?>"><i data-lucide="plus" class="icon-sm"></i>Create template</a></div></div>
<div class="row g-3"><?php foreach ($templates as $t): ?>
    <div class="col-md-6 col-xxl-4"><div class="card app-card h-100"><div class="card-body">
        <div class="fw-semibold mb-1"><a href="<?= e(route('admin.templates.show', ['id' => $t['id']])) ?>"><?= e($t['name']) ?></a></div>
        <p class="small text-secondary"><?= e($t['description'] ?? '') ?></p>
        <div class="small mb-3"><?= (int) $t['permission_count'] ?> permissions · <?= (int) $t['scope_count'] ?> data scopes</div>
        <div class="d-flex gap-2"><a class="btn btn-sm btn-light" href="<?= e(route('admin.templates.show', ['id' => $t['id']])) ?>">Edit / apply</a>
            <form method="post" action="<?= e(route('admin.templates.duplicate', ['id' => $t['id']])) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-light" type="submit">Duplicate</button></form></div>
    </div></div></div>
<?php endforeach; ?><?= $templates === [] ? '<div class="col-12">' . $view->partial('partials/empty', ['icon' => 'copy', 'text' => 'No templates yet.']) . '</div>' : '' ?></div>
