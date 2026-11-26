<?php /** @var array<string, mixed>|null $w @var list<array<string, mixed>> $options */ ?>
<div class="page-head"><div><h1>Storage locations</h1><p>Hierarchical locations inside each warehouse.</p></div>
<?php if ($w !== null): ?><form class="page-actions" method="get" action="<?= e(route('inventory.locations')) ?>"><select class="form-select" name="warehouse" aria-label="Warehouse" data-autosubmit><?php foreach ($options as $o): ?><option value="<?= (int) $o['id'] ?>" <?= (int) $o['id'] === (int) $w['id'] ? 'selected' : '' ?>><?= e($o['code'] . ' · ' . $o['name']) ?></option><?php endforeach; ?></select><noscript><button class="btn btn-light" type="submit">Show</button></noscript></form><?php endif; ?></div>
<div class="card app-card"><div class="card-body">
<?php if ($w === null): ?><?= $view->partial('partials/empty', ['icon' => 'warehouse', 'text' => 'Add a warehouse first.']) ?><?php else: ?>
    <h2 class="card-section-title"><a href="<?= e(route('inventory.warehouses.show', ['id' => $w['id']])) ?>"><?= e($w['code'] . ' · ' . $w['name']) ?></a></h2>
    <?= $view->partial('inventory/_locations', ['w' => $w, 'tree' => $tree, 'types' => $types, 'units' => $units, 'edit' => $edit, 'showArchived' => $showArchived, 'back' => 'page']) ?>
<?php endif; ?>
</div></div>
