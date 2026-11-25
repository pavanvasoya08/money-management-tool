<?php

declare(strict_types=1);

namespace App\Controllers\Product;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Exceptions\HttpException;
use App\Services\Product\CatalogService;
use App\Services\Product\ProductService;

/**
 * Shared helpers for the product pages. Every product is loaded through the user's
 * product record scope (404 outside it); every action also checks its own permission.
 * Pickers for GST registrations and suppliers only offer records the user may see.
 */
abstract class ProductBaseController extends Controller
{
    protected const CRUMB = ['Products' => '/products/dashboard'];

    /** @return array{0: string, 1: list<mixed>} */
    protected function scope(string $alias = 'p'): array
    {
        return App::dataAccess()->constrain($this->user(), 'products', $alias);
    }

    /** @return array<string, mixed> */
    protected function findProduct(string|int $id): array
    {
        $pid = is_int($id) ? $id : $this->intParam($id);
        App::dataAccess()->assertCanAccess($this->user(), 'products', $pid);
        return (new ProductService(App::db()))->find($pid) ?? throw new HttpException(404, 'Product not found.');
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

    /** Suppliers the user may see and that can be linked. @return list<array<string, mixed>> */
    /** @var array<int, true>|null */
    private ?array $visibleSuppliers = null;

    /**
     * Supplier names on product pages follow the user's supplier scope: suppliers the user may
     * not see appear as "Restricted supplier" (or are left out of lists of suppliers).
     * @param list<array<string, mixed>> $rows @return list<array<string, mixed>>
     */
    protected function redactSuppliers(array $rows, bool $drop = false): array
    {
        if ($this->visibleSuppliers === null) {
            $this->visibleSuppliers = [];
            if (can('suppliers.view')) {
                [$w, $p] = App::dataAccess()->constrain($this->user(), 'suppliers', 's');
                foreach (App::db()->select("SELECT s.id FROM suppliers s WHERE {$w}", $p) as $r) {
                    $this->visibleSuppliers[(int) $r['id']] = true;
                }
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $sid = isset($r['supplier_id']) ? (int) $r['supplier_id'] : 0;
            if ($sid === 0 || isset($this->visibleSuppliers[$sid])) {
                $out[] = $r + ['supplier_visible' => true];
            } elseif (!$drop) {
                $out[] = ['supplier_name' => 'Restricted supplier', 'supplier_code' => '', 'supplier_visible' => false] + $r;
            }
        }
        return $out;
    }

    protected function supplierOptions(): array
    {
        if (!can('suppliers.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'suppliers', 's');
        return App::db()->select("SELECT s.id, s.supplier_code, s.name, s.status FROM suppliers s WHERE {$w} AND s.status NOT IN ('archived', 'blocked') ORDER BY s.name", $p);
    }

    /** Products the user may see (for component pickers). @return list<array<string, mixed>> */
    protected function productOptions(int $exceptId = 0): array
    {
        [$w, $p] = $this->scope();
        return App::db()->select("SELECT p.id, p.product_code, p.name, p.sku FROM products p WHERE {$w} AND p.status <> 'archived' AND p.id <> ? ORDER BY p.name LIMIT 1000", array_merge($p, [$exceptId]));
    }

    /** @return array<string, mixed> category tree, brands, types, attributes for forms and filters */
    protected function masters(bool $activeOnly = false): array
    {
        $c = new CatalogService(App::db());
        return ['categories' => $c->categoryTree(false, $activeOnly), 'brands' => $c->brands(false, $activeOnly), 'types' => $c->productTypes(!$activeOnly ? false : true)];
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

    /** Back to a product tab with a flash message. */
    protected function backTo(int $productId, string $tab, string $message, string $type = 'success'): \App\Core\Response
    {
        return $this->redirectRoute('products.show', ['id' => $productId, 'tab' => $tab])->with($type, $message);
    }
}
