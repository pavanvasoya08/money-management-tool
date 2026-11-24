<?php
/**
 * Main ERP shell: sidebar, top bar, breadcrumbs, content, footer, toasts, confirm modal.
 * Menu visibility is cosmetic; every route is authorised on the server.
 * @var string $content
 */
$me = auth_user();
$currentRoute = (string) (\App\Core\App::request()?->attributes['route'] ?? '');
$appName = (string) setting('app_name', config('app.name'));
$shell = new \App\Support\UiShell($me ?? []);
$isSuper = $me !== null && $shell->isSuper();
$adminSection = $section ?? null; // Control Center page id (set by admin controllers)
$notes = $me !== null ? $shell->notifications() : ['items' => [], 'count' => 0];
$mini = \App\Support\UiShell::sidebarMini();
$crumbs = $breadcrumbs ?? [];
?>
<!doctype html>
<html lang="en">
<head>
<?= $view->partial('partials/head', ['title' => $title ?? '']) ?>
</head>
<body class="app-body<?= $mini ? ' sidebar-mini' : '' ?>">
<a class="visually-hidden-focusable skip-link" href="#main">Skip to content</a>

<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sidebar-brand text-decoration-none" href="<?= e(route('dashboard')) ?>" title="<?= e($appName) ?>">
        <span class="brand-mark" aria-hidden="true"><?= e(initials($appName)) ?></span>
        <span class="brand-text"><strong><?= e($appName) ?></strong></span>
    </a>
    <nav class="sidebar-nav" data-sidebar-nav>
        <?php foreach ($shell->navigation($currentRoute) as $navSection): ?>
            <div class="nav-section"><?= e($navSection['section']) ?></div>
            <?php foreach ($navSection['entries'] as $entry): ?>
                <?php if (isset($entry['children'])): ?>
                    <?php $open = $entry['active']; $gid = 'nav-g-' . $entry['group']; ?>
                    <div class="nav-group<?= $open ? ' open has-active' : '' ?>" data-nav-group="<?= e($entry['group']) ?>">
                        <button class="nav-group-toggle" type="button" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="<?= e($gid) ?>" title="<?= e($entry['label']) ?>">
                            <i data-lucide="<?= e($entry['icon']) ?>"></i><span class="label"><?= e($entry['label']) ?></span><i data-lucide="chevron-down" class="chev"></i>
                        </button>
                        <ul class="nav-sub" id="<?= e($gid) ?>">
                            <?php foreach ($entry['children'] as $c): ?>
                                <li><?= $view->partial('partials/nav-item', ['item' => $c]) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <?= $view->partial('partials/nav-item', ['item' => $entry]) ?>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-foot"><span class="phase-chip">Phase 3 · Partners &amp; Capital</span></div>
</aside>
<div class="sidebar-backdrop" data-sidebar-close></div>

<div class="main">
    <header class="topbar">
        <button class="btn btn-icon" type="button" data-sidebar-toggle aria-label="Toggle navigation" aria-controls="sidebar">
            <i data-lucide="align-left"></i>
        </button>
        <button class="btn btn-icon" type="button" data-bs-toggle="modal" data-bs-target="#quickNav" aria-label="Search pages (Ctrl+K)" title="Search pages (Ctrl+K)">
            <i data-lucide="search"></i>
        </button>
        <div class="topbar-right">
            <div class="dropdown">
                <button class="btn btn-icon" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications<?= $notes['count'] ? ' (' . (int) $notes['count'] . ' new)' : '' ?>">
                    <i data-lucide="bell"></i><?php if ($notes['count'] > 0): ?><span class="notif-dot" aria-hidden="true"></span><?php endif; ?>
                </button>
                <div class="dropdown-menu dropdown-menu-end notif-menu">
                    <div class="notif-head"><span>Notifications</span><?php if ($notes['count'] > 0): ?><span class="badge badge-soft-primary"><?= (int) $notes['count'] ?> pending</span><?php endif; ?></div>
                    <?php if ($notes['items'] === []): ?>
                        <div class="empty-state py-4"><i data-lucide="bell-off"></i><p class="mb-0 small">You're all caught up.</p></div>
                    <?php else: ?>
                        <?php foreach ($notes['items'] as $n): ?>
                            <a class="notif-item" href="<?= e($n['url']) ?>">
                                <span class="avatar tone-blue"><i data-lucide="<?= e($n['icon']) ?>" class="icon-sm"></i></span>
                                <span class="t"><?= $n['html'] ?><span class="when"><?= e(time_ago($n['when'])) ?></span></span>
                                <span class="unread" aria-hidden="true"></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if ($shell->isApprover()): ?><a class="btn btn-primary" href="<?= e(route('approvals.index')) ?>">View all approvals</a><?php endif; ?>
                </div>
            </div>
            <?php if (can('settings.view')): ?>
                <a class="btn btn-icon" href="<?= e(route('settings.index')) ?>" aria-label="Settings" title="Settings"><i data-lucide="settings"></i></a>
            <?php endif; ?>
            <div class="dropdown">
                <button class="profile-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Account menu for <?= e($me['name'] ?? '') ?>">
                    <span class="avatar"><?= e(initials((string) ($me['name'] ?? '?'))) ?></span>
                </button>
                <div class="dropdown-menu dropdown-menu-end">
                    <div class="menu-profile">
                        <span class="avatar"><?= e(initials((string) ($me['name'] ?? '?'))) ?></span>
                        <span class="min-w-0"><span class="name d-block text-truncate"><?= e($me['name'] ?? '') ?></span><span class="sub d-block text-truncate"><?= e($me['email'] ?? '') ?></span></span>
                    </div>
                    <a class="dropdown-item" href="<?= e(route('account.profile')) ?>"><i data-lucide="user"></i>Profile</a>
                    <a class="dropdown-item" href="<?= e(route('account.password')) ?>"><i data-lucide="lock-keyhole"></i>Security</a>
                    <?php if (can('activity_logs.view')): ?><a class="dropdown-item" href="<?= e(route('activity.index')) ?>"><i data-lucide="activity"></i>Activity log</a><?php endif; ?>
                    <?php if ($isSuper): ?><a class="dropdown-item" href="<?= e(route('admin.overview')) ?>"><i data-lucide="shield-check"></i>Control Center</a><?php endif; ?>
                    <div class="dropdown-divider"></div>
                    <form method="post" action="<?= e(route('logout')) ?>">
                        <?= csrf_field() ?>
                        <button class="dropdown-item" type="submit"><i data-lucide="log-out"></i>Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <main id="main" class="content" tabindex="-1">
        <?php if (count($crumbs) > 1): ?>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?= e(route('dashboard')) ?>">Home</a></li>
                    <?php foreach ($crumbs as $label => $url): ?>
                        <?php if ($url === null): ?>
                            <li class="breadcrumb-item active" aria-current="page"><?= e($label) ?></li>
                        <?php else: ?>
                            <li class="breadcrumb-item"><a href="<?= e($url) ?>"><?= e($label) ?></a></li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ol>
            </nav>
        <?php endif; ?>
        <?php if ($adminSection !== null): ?>
            <div class="admin-shell">
                <?= $view->partial('admin/_nav', ['section' => $adminSection]) ?>
                <div class="admin-main"><?= $content ?></div>
            </div>
        <?php else: ?>
            <?= $content ?>
        <?php endif; ?>
    </main>
    <footer class="app-footer">
        <span>Copyright &copy; <?= gmdate('Y') ?> <b><?= e(setting('company_name', '') ?: $appName) ?></b> All rights reserved.</span>
        <span><?= e($appName) ?> v<?= e(config('app.version')) ?></span>
    </footer>
</div>

<div class="modal fade" id="quickNav" tabindex="-1" aria-labelledby="quickNavTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header pb-3">
                <div class="input-icon flex-grow-1"><i data-lucide="search"></i>
                    <input class="form-control" type="search" id="quickNavInput" placeholder="Search pages and actions…" autocomplete="off" aria-label="Search pages and actions" data-quick-nav-input></div>
                <h2 class="visually-hidden" id="quickNavTitle">Quick navigation</h2>
            </div>
            <div class="modal-body pt-0">
                <ul class="quick-nav-list" data-quick-nav-list>
                    <?php foreach ($shell->quickLinks() as $q): ?>
                        <li data-search="<?= e(mb_strtolower($q['label'] . ' ' . $q['group'])) ?>"><a href="<?= e($q['url']) ?>"><i data-lucide="<?= e($q['icon']) ?>"></i><?= e($q['label']) ?><span class="grp"><?= e($q['group']) ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
                <p class="text-secondary small mb-0 mt-2 d-none" data-quick-nav-empty>No matching pages.</p>
            </div>
        </div>
    </div>
</div>

<?= $view->partial('partials/toasts') ?>
<?= $view->partial('partials/confirm-modal') ?>
<?= $view->partial('partials/scripts', ['withCharts' => $withCharts ?? false, 'withAdmin' => $adminSection !== null]) ?>
</body>
</html>
