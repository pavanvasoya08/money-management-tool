<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Supplier\SupplierService;

/** Shared helpers for supplier pages: records load through the supplier record scope (404 outside it). */
abstract class SupplierBaseController extends Controller
{
    protected const CRUMB = ['Suppliers' => '/suppliers/dashboard'];

    /** @return array{0: string, 1: list<mixed>} */
    protected function scope(string $alias = 's'): array
    {
        return App::dataAccess()->constrain($this->user(), 'suppliers', $alias);
    }

    /** @return array<string, mixed> */
    protected function findSupplier(string|int $id): array
    {
        $sid = is_int($id) ? $id : $this->intParam($id);
        App::dataAccess()->assertCanAccess($this->user(), 'suppliers', $sid);
        return (new SupplierService(App::db()))->find($sid) ?? throw new HttpException(404, 'Supplier not found.');
    }

    /** @return list<array<string, mixed>> */
    protected function gstOptions(): array
    {
        if (!can('gst_registrations.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'gst_registrations', 'g');
        return App::db()->select("SELECT g.id, g.gstin, g.legal_name, g.trade_name FROM gst_registrations g WHERE {$w} AND g.is_archived = 0 AND g.status <> 'cancelled' ORDER BY g.gstin", $p);
    }

    /** @return list<array<string, mixed>> */
    protected function activityOptions(): array
    {
        return App::db()->select('SELECT id, code, name FROM gst_business_activities WHERE is_active = 1 ORDER BY sort_order, name');
    }

    /** Products the user may see (for mapping from the supplier side). @return list<array<string, mixed>> */
    protected function productOptions(): array
    {
        if (!can('products.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'products', 'p');
        return App::db()->select("SELECT p.id, p.product_code, p.name, p.sku FROM products p WHERE {$w} AND p.status <> 'archived' ORDER BY p.name LIMIT 1000", $p);
    }

    /** Bank scope for payment references (null when the user cannot see bank accounts). @return array{0: string, 1: list<mixed>}|null */
    protected function bankScope(): ?array
    {
        return can('bank_accounts.view') ? App::dataAccess()->constrain($this->user(), 'bank_accounts', 'a') : null;
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request, array $text, array $ints): array
    {
        $f = [];
        foreach ($text as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
            if (in_array($k, ['from', 'to', 'as_of'], true) && $f[$k] !== '' && !\App\Core\Validator::isDate($f[$k])) {
                $f[$k] = '';
            }
        }
        foreach ($ints as $k) {
            $f[$k] = max(0, (int) $request->input($k, 0)) ?: '';
        }
        return $f;
    }

    protected function backTo(int $supplierId, string $tab, string $message, string $type = 'success'): Response
    {
        return $this->redirectRoute('suppliers.show', ['id' => $supplierId, 'tab' => $tab])->with($type, $message);
    }
}
