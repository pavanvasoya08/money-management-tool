<?php
/** @var list<array<string, mixed>> $history @var array<string, mixed> $f @var string $event @var list<array<string, mixed>> $options @var list<string> $events */
use App\Services\Ecommerce\PlatformHistory;

$sel = static fn (string $k, int|string $v): string => (string) ($f[$k] ?? '') === (string) $v ? 'selected' : '';
?>
<div class="card app-card list-card">
    <div class="list-card-head"><div><h1>Platform history</h1><p>Every change to platforms, configuration, mappings, documents and notes. Entries are never edited or deleted.</p></div></div>
    <form class="table-toolbar flex-wrap" method="get" action="<?= e(route('ecommerce.history')) ?>" role="search">
        <select class="form-select toolbar-select" name="platform" aria-label="Platform"><option value="">All platforms</option><?php foreach ($options as $o): ?><option value="<?= (int) $o['id'] ?>" <?= $sel('platform', $o['id']) ?>><?= e($o['name']) ?></option><?php endforeach; ?></select>
        <select class="form-select toolbar-select" name="event" aria-label="Event"><option value="">Any event</option><?php foreach ($events as $ev): ?><option value="<?= e($ev) ?>" <?= $event === $ev ? 'selected' : '' ?>><?= e(PlatformHistory::label($ev)) ?></option><?php endforeach; ?></select>
        <input class="form-control toolbar-select" type="date" name="from" value="<?= e($f['from']) ?>" aria-label="From">
        <input class="form-control toolbar-select" type="date" name="to" value="<?= e($f['to']) ?>" aria-label="To">
        <button class="btn btn-outline-secondary" type="submit"><i data-lucide="filter" class="icon-sm"></i>Apply</button>
        <?php if (array_filter($f) || $event !== ''): ?><a class="btn btn-link text-secondary" href="<?= e(route('ecommerce.history')) ?>">Clear</a><?php endif; ?>
    </form>
    <?= $view->partial('ecommerce/_history', ['history' => $history, 'showPlatform' => true, 'seePartners' => \App\Core\App::fields()->access(auth_user(), 'platforms', 'partner_mappings')->canView()]) ?>
</div>
