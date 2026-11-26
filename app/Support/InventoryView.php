<?php

declare(strict_types=1);

namespace App\Support;

use App\Core\App;
use App\Services\Authorization\FieldPolicy;

/**
 * View helpers for inventory pages: quantities, costs and values honouring both the
 * inventory.view_stock_cost permission and the stock_value / unit_cost field rules.
 */
final class InventoryView
{
    private const DOC_TONES = ['draft' => 'secondary', 'pending_approval' => 'warning', 'approved' => 'info', 'posted' => 'success', 'rejected' => 'danger', 'cancelled' => 'dark',
        'reversed' => 'dark', 'in_transit' => 'info', 'partially_received' => 'warning', 'received' => 'success', 'closed' => 'success', 'active' => 'success', 'inactive' => 'secondary',
        'archived' => 'dark', 'partially_fulfilled' => 'warning', 'fulfilled' => 'success', 'released' => 'secondary', 'expired' => 'danger', 'on_hold' => 'warning',
        'in_stock' => 'success', 'low' => 'warning', 'out' => 'danger', 'negative' => 'danger', 'over' => 'info', 'near_expiry' => 'warning', 'ok' => 'success',
        'dispatched' => 'secondary', 'returned_to_supplier' => 'secondary', 'consumed' => 'secondary', 'written_off' => 'danger', 'discontinued' => 'dark'];

    /** Can the signed-in user see stock values (permission + field rule)? */
    public static function seesValue(): bool
    {
        $u = App::auth()->user();
        return $u !== null && can('inventory.view_stock_cost') && App::fields()->access($u, 'inventory', 'stock_value')->canView();
    }

    public static function seesUnitCost(): bool
    {
        $u = App::auth()->user();
        return $u !== null && can('inventory.view_stock_cost') && App::fields()->access($u, 'inventory', 'unit_cost')->canView();
    }

    /** Stock value in paise. */
    public static function value(?int $paise): string
    {
        if ($paise === null) {
            return '<span class="text-secondary">—</span>';
        }
        $u = App::auth()->user();
        if ($u === null || !self::seesValue()) {
            return '<span class="text-secondary" title="Hidden by your permissions">Hidden</span>';
        }
        if (!App::fields()->access($u, 'inventory', 'stock_value')->showsRealValue()) {
            return '<span class="text-secondary" title="Masked by your field permissions">' . e(FieldPolicy::mask(Money::toDb($paise), 'amount')) . '</span>';
        }
        return '<span class="' . ($paise < 0 ? 'text-danger' : '') . '">' . e(Money::format($paise)) . '</span>';
    }

    /** Unit cost (scaled 1/10000 rupee). */
    public static function cost(?int $cost): string
    {
        if ($cost === null) {
            return '<span class="text-secondary">—</span>';
        }
        $u = App::auth()->user();
        if ($u === null || !self::seesUnitCost()) {
            return '<span class="text-secondary" title="Hidden by your permissions">Hidden</span>';
        }
        $txt = '₹' . (string) preg_replace('/(\.\d{2}\d*?)0+$/', '$1', Qty::format($cost, 4));
        if (!App::fields()->access($u, 'inventory', 'unit_cost')->showsRealValue()) {
            return '<span class="text-secondary" title="Masked by your field permissions">' . e(FieldPolicy::mask(Qty::toDb($cost), 'amount')) . '</span>';
        }
        return '<span>' . e($txt) . '</span>';
    }

    /** Quantity with unit; negative in red. */
    public static function qty(int|string|null $q, string $unit = ''): string
    {
        if ($q === null || $q === '') {
            return '<span class="text-secondary">—</span>';
        }
        $n = is_int($q) ? $q : Qty::fromDb($q);
        return '<span class="text-nowrap' . ($n < 0 ? ' text-danger' : '') . '">' . e(Qty::format($n)) . ($unit !== '' ? ' <small class="text-secondary">' . e($unit) . '</small>' : '') . '</span>';
    }

    /** Quantity read straight from a DECIMAL column (string on MySQL, int or string on SQLite). */
    public static function dbQty(int|string|null $q, string $unit = ''): string
    {
        return $q === null || $q === '' ? self::qty(null) : self::qty(Qty::fromDb((string) $q), $unit);
    }

    /** @param array<string, string> $labels */
    public static function badge(string $status, array $labels = []): string
    {
        $tone = self::DOC_TONES[$status] ?? 'secondary';
        return '<span class="badge rounded-pill badge-soft-' . $tone . '">' . e($labels[$status] ?? ucfirst(str_replace('_', ' ', $status))) . '</span>';
    }

    /** Product label with variant. @param array<string, mixed> $r */
    public static function item(array $r, bool $link = true): string
    {
        $name = e((string) ($r['product_name'] ?? ''));
        if ($link && can('inventory.view') && isset($r['product_id'])) {
            $name = '<a class="fw-semibold" href="' . e(route('inventory.products.show', ['id' => $r['product_id']])) . '">' . $name . '</a>';
        } else {
            $name = '<b>' . $name . '</b>';
        }
        $sub = array_filter([(string) ($r['variant_name'] ?? ''), (string) ($r['variant_sku'] ?? $r['sku'] ?? $r['product_code'] ?? '')]);
        return $name . ($sub !== [] ? '<span class="d-block small text-secondary">' . e(implode(' · ', $sub)) . '</span>' : '');
    }

    /** Link to an approval request when the user may open it (same rule as the approvals page), else ''. */
    public static function approvalLink(mixed $requestId, string $text = 'view request'): string
    {
        $me = App::auth()->user();
        if ($requestId === null || $me === null) {
            return '';
        }
        $req = App::db()->first('SELECT r.requested_by, m.module_key, w.approve_action, w.reject_action FROM approval_requests r JOIN approval_workflows w ON w.id = r.workflow_id JOIN modules m ON m.id = w.module_id WHERE r.id = ?', [(int) $requestId]);
        if ($req === null) {
            return '';
        }
        $gate = App::gate();
        $ok = $gate->isSuperAdmin($me) || (int) $req['requested_by'] === (int) $me['id']
            || $gate->allows($me, $req['module_key'] . '.' . ($req['approve_action'] ?: 'approve')) || $gate->allows($me, $req['module_key'] . '.' . ($req['reject_action'] ?: 'reject'));
        return $ok ? '<a href="' . e(route('approvals.show', ['id' => (int) $requestId])) . '">' . e($text) . '</a>' : '';
    }
}
