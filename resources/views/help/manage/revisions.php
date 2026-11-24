<?php /** @var array<string, mixed> $guide @var list<array<string, mixed>> $revisions */ ?>
<div class="card app-card list-card">
    <div class="list-card-head">
        <div><h1>Revision history</h1><p><?= e($guide['title']) ?> · every save, publish and unpublish keeps a full read-only snapshot.</p></div>
        <a class="btn btn-light" href="<?= e(route('help.manage.guides.edit', ['id' => $guide['id']])) ?>">Back to editor</a>
    </div>
    <?php if ($revisions === []): ?>
        <?= $view->partial('partials/empty', ['icon' => 'history', 'text' => 'No revisions yet.']) ?>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table app-table mb-0 table-stack">
            <thead><tr><th>Revision</th><th>Event</th><th>Change note</th><th>By</th><th>When</th><th class="text-end">Snapshot</th></tr></thead>
            <tbody>
            <?php foreach ($revisions as $r): ?>
                <tr>
                    <td data-label="Revision" class="fw-semibold">#<?= (int) $r['revision_no'] ?></td>
                    <td data-label="Event"><span class="badge badge-soft-<?= ['published' => 'success', 'unpublished' => 'warning', 'created' => 'info', 'seeded' => 'info'][$r['event']] ?? 'secondary' ?>"><?= e(ucfirst((string) $r['event'])) ?></span></td>
                    <td data-label="Change note"><?= e($r['change_note'] ?? '—') ?></td>
                    <td data-label="By"><?= e($r['author'] ?? 'System') ?></td>
                    <td data-label="When" class="text-nowrap"><?= e(format_datetime($r['created_at'])) ?></td>
                    <td class="text-end"><a class="btn btn-sm btn-light" href="<?= e(route('help.manage.guides.revision', ['id' => $guide['id'], 'rev' => $r['id']])) ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
