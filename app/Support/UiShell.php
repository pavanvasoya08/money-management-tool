<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\App;
use App\Core\Config;

/**
 * View model for the application shell (sidebar, top bar menus).
 *
 * Visibility here is cosmetic only: every route is still protected by its
 * `can:` middleware, record scope and field rules on the server.
 */
final class UiShell
{
    /** @var array<string, mixed> */
    private array $user;
    private bool $isSuper;
    private ?bool $approver = null;

    /** @param array<string, mixed> $user */
    public function __construct(array $user)
    {
        $this->user = $user;
        $this->isSuper = App::gate()->isSuperAdmin($user);
    }

    public function isSuper(): bool
    {
        return $this->isSuper;
    }

    /** Can the user approve or reject anything (shows the Approvals entry)? */
    public function isApprover(): bool
    {
        if ($this->approver === null) {
            $this->approver = $this->isSuper;
            if (!$this->approver) {
                foreach (App::db()->select("SELECT slug FROM permissions WHERE action IN ('approve', 'reject')") as $p) {
                    if (can((string) $p['slug'])) {
                        $this->approver = true;
                        break;
                    }
                }
            }
        }
        return $this->approver;
    }

    /**
     * Sidebar tree: sections → entries, where an entry is a link, a disabled
     * "Soon" item, or a collapsible group of those. Items the user may not
     * open are left out; groups with no visible children disappear.
     * @return list<array<string, mixed>>
     */
    public function navigation(string $currentRoute): array
    {
        $tree = [];
        $section = null; // reference to the current section in $tree
        foreach ((array) Config::get('navigation', []) as $item) {
            if (isset($item['section'])) {
                unset($section);
                $tree[] = ['section' => (string) $item['section'], 'entries' => []];
                $section = &$tree[array_key_last($tree)];
                continue;
            }
            $entry = $this->entry($item, $currentRoute);
            if ($entry === null || $section === null) {
                continue;
            }
            if (isset($item['group'])) {
                $g = (array) $item['group'];
                $last = array_key_last($section['entries']);
                if ($last === null || ($section['entries'][$last]['group'] ?? null) !== $g['key']) {
                    $section['entries'][] = ['group' => (string) $g['key'], 'label' => (string) $g['label'], 'icon' => (string) $g['icon'], 'children' => [], 'active' => false];
                    $last = array_key_last($section['entries']);
                }
                $section['entries'][$last]['children'][] = $entry;
                $section['entries'][$last]['active'] = $section['entries'][$last]['active'] || $entry['active'];
                continue;
            }
            $section['entries'][] = $entry;
        }
        unset($section);
        return array_values(array_filter($tree, static fn (array $s): bool => $s['entries'] !== []));
    }

    /** Flat list of pages the user can open (quick navigation dialog). @return list<array{label: string, url: string, icon: string, group: string}> */
    public function quickLinks(): array
    {
        $out = [];
        foreach ($this->navigation('') as $section) {
            foreach ($section['entries'] as $e) {
                foreach (isset($e['children']) ? $e['children'] : [$e] as $link) {
                    if (!empty($link['url'])) {
                        $out[] = ['label' => (string) ($link['title'] ?? $link['label']), 'url' => (string) $link['url'], 'icon' => (string) $link['icon'], 'group' => $section['section']];
                    }
                }
            }
        }
        $out[] = ['label' => 'My profile', 'url' => route('account.profile'), 'icon' => 'user', 'group' => 'Account'];
        $out[] = ['label' => 'Change password', 'url' => route('account.password'), 'icon' => 'lock-keyhole', 'group' => 'Account'];
        if (can('partners.create')) {
            $out[] = ['label' => 'Add partner', 'url' => route('partners.create'), 'icon' => 'user-plus', 'group' => 'Actions'];
        }
        if (can('capital_contributions.create')) {
            $out[] = ['label' => 'Record capital contribution', 'url' => route('capital.contributions.create'), 'icon' => 'piggy-bank', 'group' => 'Actions'];
        }
        if (can('users.create')) {
            $out[] = ['label' => 'Create user', 'url' => route('users.create'), 'icon' => 'user-plus', 'group' => 'Actions'];
        }
        return $out;
    }

    /**
     * Real notifications only: approval requests waiting for this user and,
     * for Super Admins, pending access requests.
     * @return array{items: list<array<string, mixed>>, count: int}
     */
    public function notifications(int $limit = 5): array
    {
        $items = [];
        $count = 0;
        if ($this->isApprover()) {
            $pending = App::approvals()->pendingFor($this->user, 50);
            $count += count($pending);
            foreach (array_slice($pending, 0, $limit) as $r) {
                $items[] = [
                    'icon' => 'stamp', 'url' => route('approvals.show', ['id' => $r['id']]), 'when' => (string) $r['submitted_at'],
                    'html' => '<b>' . e((string) $r['requester_name']) . '</b> requested approval: ' . e((string) $r['title']),
                ];
            }
        }
        if ($this->isSuper && (bool) setting('access_requests_enabled', false)) {
            $rows = App::db()->select("SELECT a.id, a.created_at, u.name, p.slug FROM access_requests a JOIN users u ON u.id = a.user_id JOIN permissions p ON p.id = a.permission_id WHERE a.status = 'pending' ORDER BY a.id DESC LIMIT " . $limit);
            $count += (int) App::db()->scalar("SELECT COUNT(*) FROM access_requests WHERE status = 'pending'");
            foreach ($rows as $r) {
                $items[] = [
                    'icon' => 'hand', 'url' => route('admin.access-requests'), 'when' => (string) $r['created_at'],
                    'html' => '<b>' . e((string) $r['name']) . '</b> asked for <b>' . e((string) $r['slug']) . '</b>',
                ];
            }
        }
        usort($items, static fn (array $a, array $b): int => strcmp($b['when'], $a['when']));
        return ['items' => array_slice($items, 0, $limit), 'count' => $count];
    }

    /** Sidebar collapsed on desktop? Remembered in a cookie so the page renders without a flash. */
    public static function sidebarMini(): bool
    {
        return (App::request()?->cookies['ui_sidebar'] ?? '') === 'mini';
    }

    /** @param array<string, mixed> $item @return array<string, mixed>|null */
    private function entry(array $item, string $currentRoute): ?array
    {
        $base = ['label' => (string) $item['label'], 'title' => $item['title'] ?? null, 'icon' => (string) ($item['icon'] ?? 'circle'), 'group' => null, 'active' => false, 'url' => null];
        if (($item['route'] ?? null) === null) {
            return $base + ['soon' => true, 'phase' => (int) ($item['phase'] ?? 0)];
        }
        if (!empty($item['super_admin']) && !$this->isSuper) {
            return null;
        }
        if (!empty($item['approver']) && !$this->isApprover()) {
            return null;
        }
        if (($item['permission'] ?? null) !== null && !can((string) $item['permission'])) {
            return null;
        }
        $active = isset($item['active']) && $currentRoute !== '' && str_starts_with($currentRoute, (string) $item['active']);
        return ['soon' => false, 'url' => route((string) $item['route']), 'active' => $active] + $base;
    }
}
