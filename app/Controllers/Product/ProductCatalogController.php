<?php

declare(strict_types=1);

namespace App\Controllers\Product;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Product\CatalogService;
use App\Services\Product\PricingService;
use App\Services\Product\ProductMediaService;
use App\Services\Product\TaxConfigService;
use App\Services\Product\VariantService;
use App\Services\SettingsService;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Catalogue masters (categories, brands, attributes, product types, tax masters) and the
 * cross-product pages: variants, pricing, GST/HSN, supplier mappings and documents. Lists
 * only include products in the user's record scope.
 */
final class ProductCatalogController extends ProductBaseController
{
    private CatalogService $catalog;

    public function __construct()
    {
        $this->catalog = new CatalogService(App::db());
    }

    // ---------------------------------------------------------------- categories & brands

    public function categories(Request $request): Response
    {
        $archived = $request->string('archived') === '1';
        $edit = (int) $request->input('edit', 0);
        return $this->view('products/categories', ['title' => 'Product categories', 'breadcrumbs' => self::CRUMB + ['Categories' => null],
            'tree' => $this->catalog->categoryTree($archived), 'edit' => $edit > 0 ? $this->catalog->category($edit) : null, 'showArchived' => $archived,
            'parents' => $this->catalog->categoryTree()]);
    }

    public function saveCategory(Request $request, ?string $id = null): Response
    {
        $cid = $id !== null ? $this->intParam($id) : null;
        $this->catalog->saveCategory($request->all(), $this->user(), $cid, $request->file('image'));
        return $this->redirectRoute('products.categories')->with('success', $cid ? 'Category updated.' : 'Category added.');
    }

    public function categoryStatus(Request $request, string $id): Response
    {
        $this->catalog->setMasterStatus('category', $this->intParam($id), $request->string('action'), $request->string('reason') ?: null, $this->user());
        return $this->redirectRoute('products.categories', $request->string('action') === 'restore' ? ['archived' => 1] : [])->with('success', 'Category updated.');
    }

    public function categoryImage(Request $request, string $id): Response
    {
        return $this->catalog->imageResponse('category', $this->intParam($id));
    }

    public function brands(Request $request): Response
    {
        $archived = $request->string('archived') === '1';
        $edit = (int) $request->input('edit', 0);
        return $this->view('products/brands', ['title' => 'Product brands', 'breadcrumbs' => self::CRUMB + ['Brands' => null],
            'brands' => $this->catalog->brands($archived), 'edit' => $edit > 0 ? $this->catalog->brand($edit) : null, 'showArchived' => $archived]);
    }

    public function saveBrand(Request $request, ?string $id = null): Response
    {
        $bid = $id !== null ? $this->intParam($id) : null;
        $this->catalog->saveBrand($request->all(), $this->user(), $bid, $request->file('logo'));
        return $this->redirectRoute('products.brands')->with('success', $bid ? 'Brand updated.' : 'Brand added.');
    }

    public function brandStatus(Request $request, string $id): Response
    {
        $this->catalog->setMasterStatus('brand', $this->intParam($id), $request->string('action'), $request->string('reason') ?: null, $this->user());
        return $this->redirectRoute('products.brands', $request->string('action') === 'restore' ? ['archived' => 1] : [])->with('success', 'Brand updated.');
    }

    public function brandLogo(Request $request, string $id): Response
    {
        return $this->catalog->imageResponse('brand', $this->intParam($id));
    }

    // ---------------------------------------------------------------- attributes

    public function attributes(Request $request): Response
    {
        return $this->view('products/attributes', ['title' => 'Product attributes', 'breadcrumbs' => self::CRUMB + ['Attributes' => null], 'attributes' => $this->catalog->attributes()]);
    }

    public function saveAttribute(Request $request, ?string $id = null): Response
    {
        $aid = $id !== null ? $this->intParam($id) : null;
        $this->catalog->saveAttribute($request->all() + ($aid === null ? ['is_active' => 1] : []), $this->user(), $aid);
        return $this->redirectRoute('products.attributes')->with('success', $aid ? 'Attribute updated.' : 'Attribute added.');
    }

    public function saveValue(Request $request, string $id, ?string $value = null): Response
    {
        $this->catalog->saveAttributeValue($this->intParam($id), $request->string('value'), (int) $request->input('sort_order', 0), $value === null || $request->string('is_active') === '1', $this->user(),
            $value !== null ? $this->intParam($value) : null);
        return $this->redirectRoute('products.attributes')->with('success', 'Attribute value saved.');
    }

    // ---------------------------------------------------------------- cross-product lists

    public function variants(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'status'], ['category', 'brand']);
        $sql = "SELECT v.*, p.product_code, p.name AS product_name, p.status AS product_status FROM product_variants v JOIN products p ON p.id = v.product_id WHERE {$w}";
        $params = $p;
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(v.name) LIKE ? OR LOWER(v.sku) LIKE ? OR LOWER(v.variant_code) LIKE ? OR LOWER(p.name) LIKE ? OR v.barcode = ?)';
            array_push($params, $like, $like, $like, $like, $f['q']);
        }
        if (isset(VariantService::STATUSES[$f['status']])) {
            $sql .= ' AND v.status = ?';
            $params[] = $f['status'];
        } else {
            $sql .= " AND v.status <> 'archived'";
        }
        foreach (['category' => '(p.category_id = ? OR p.subcategory_id = ?)', 'brand' => 'p.brand_id = ?'] as $k => $c) {
            if ((int) $f[$k] > 0) {
                $sql .= " AND {$c}";
                array_push($params, ...array_fill(0, substr_count($c, '?'), (int) $f[$k]));
            }
        }
        $page = max(1, (int) $request->input('page', 1));
        $per = (int) setting('records_per_page', 15) * 2;
        $total = (int) App::db()->scalar("SELECT COUNT(*) FROM ({$sql}) x", $params);
        $rows = App::db()->select($sql . ' ORDER BY p.name, v.sort_order, v.id LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
        $attrs = [];
        if ($rows !== []) {
            foreach (App::db()->select('SELECT va.variant_id, a.name AS attribute, COALESCE(av.value, va.value_text) AS value FROM product_variant_attributes va JOIN product_attributes a ON a.id = va.attribute_id
                LEFT JOIN product_attribute_values av ON av.id = va.value_id WHERE va.variant_id IN (' . implode(',', array_map(static fn (array $r): int => (int) $r['id'], $rows)) . ') ORDER BY a.sort_order', []) as $a) {
                $attrs[(int) $a['variant_id']][] = $a['attribute'] . ': ' . $a['value'];
            }
        }
        return $this->view('products/variants', ['title' => 'Product variants', 'breadcrumbs' => self::CRUMB + ['Variants' => null], 'rows' => new Paginator($rows, $total, $page, $per),
            'attrs' => $attrs, 'f' => $f] + $this->masters());
    }

    public function pricing(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'price_type', 'as_of', 'status'], ['supplier']);
        $date = $f['as_of'] ?: BusinessDate::today();
        $pending = $f['status'] === 'pending';
        $sql = "SELECT pr.*, p.product_code, p.name AS product_name, v.name AS variant_name, s.name AS supplier_name, g.gstin FROM product_prices pr JOIN products p ON p.id = pr.product_id
            LEFT JOIN product_variants v ON v.id = pr.variant_id LEFT JOIN suppliers s ON s.id = pr.supplier_id LEFT JOIN gst_registrations g ON g.id = pr.gst_registration_id WHERE {$w}";
        $params = $p;
        if ($pending) {
            $sql .= " AND pr.status = 'pending_approval'";
        } else {
            $sql .= " AND pr.status IN ('active', 'superseded') AND pr.effective_from <= ? AND (pr.effective_until IS NULL OR pr.effective_until >= ?)";
            array_push($params, $date, $date);
        }
        if (isset(PricingService::TYPES[$f['price_type']])) {
            $sql .= ' AND pr.price_type = ?';
            $params[] = $f['price_type'];
        }
        if ((int) $f['supplier'] > 0) {
            $sql .= ' AND pr.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(p.name) LIKE ? OR LOWER(p.product_code) LIKE ? OR LOWER(p.sku) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $page = max(1, (int) $request->input('page', 1));
        $per = 40;
        $total = (int) App::db()->scalar("SELECT COUNT(*) FROM ({$sql}) x", $params);
        $rows = $this->redactSuppliers(App::db()->select($sql . ' ORDER BY p.name, v.name, pr.price_type LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params));
        return $this->view('products/pricing', ['title' => 'Product pricing', 'breadcrumbs' => self::CRUMB + ['Pricing' => null], 'rows' => new Paginator($rows, $total, $page, $per),
            'f' => $f, 'date' => $date, 'types' => PricingService::TYPES, 'suppliers' => $this->supplierOptions(), 'fields' => App::fields()->accessMap($this->user(), 'products')]);
    }

    public function tax(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'view', 'hsn'], ['rate']);
        $svc = new TaxConfigService(App::db());
        $today = BusinessDate::today();
        $sql = "SELECT t.*, p.product_code, p.name AS product_name, v.name AS variant_name, g.gstin, r.name AS rate_name, c.name AS category_name FROM product_tax_configs t JOIN products p ON p.id = t.product_id
            LEFT JOIN product_variants v ON v.id = t.variant_id LEFT JOIN gst_registrations g ON g.id = t.gst_registration_id LEFT JOIN gst_tax_rates r ON r.id = t.gst_rate_id
            JOIN product_tax_categories c ON c.id = t.tax_category_id WHERE {$w}";
        $params = $p;
        if ($f['view'] === 'pending') {
            $sql .= " AND t.status = 'pending_approval'";
        } else {
            $sql .= " AND t.status IN ('active', 'superseded') AND t.effective_from <= ? AND (t.effective_until IS NULL OR t.effective_until >= ?)";
            array_push($params, $today, $today);
        }
        if (($hsn = preg_replace('/\D/', '', $f['hsn'])) !== '') {
            $sql .= ' AND (t.hsn_code LIKE ? OR t.sac_code LIKE ?)';
            array_push($params, $hsn . '%', $hsn . '%');
        }
        if ((int) $f['rate'] > 0) {
            $sql .= ' AND t.gst_rate_id = ?';
            $params[] = (int) $f['rate'];
        }
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(p.name) LIKE ? OR LOWER(p.product_code) LIKE ?)';
            array_push($params, $like, $like);
        }
        [$mw, $mp] = (new \App\Services\Product\ProductService(App::db()))->filterSql(['missing' => 'tax'], $this->scope());
        return $this->view('products/tax', ['title' => 'GST / HSN configuration', 'breadcrumbs' => self::CRUMB + ['GST / HSN' => null],
            'rows' => App::db()->select($sql . ' ORDER BY p.name, v.name LIMIT 500', $params), 'f' => $f, 'rates' => $svc->rates(), 'taxCategories' => $svc->categories(), 'codes' => $svc->codes($request->string('code_q')),
            'tab' => $request->string('tab') === 'masters' ? 'masters' : 'configs', 'codeQ' => $request->string('code_q'), 'missing' => (int) App::db()->scalar("SELECT COUNT(*) FROM products p WHERE {$mw}", $mp), 'canManage' => can('products.manage_settings'),
            'fields' => App::fields()->accessMap($this->user(), 'products')]);
    }

    public function saveRate(Request $request, ?string $id = null): Response
    {
        (new TaxConfigService(App::db()))->saveRate($request->all() + ($id === null ? ['is_active' => 1] : []), $this->user(), $id !== null ? $this->intParam($id) : null);
        return $this->redirectRoute('products.tax', ['tab' => 'masters'])->with('success', 'GST rate saved.');
    }

    public function saveTaxCategory(Request $request, ?string $id = null): Response
    {
        (new TaxConfigService(App::db()))->saveCategory($request->all() + ($id === null ? ['is_active' => 1] : []), $this->user(), $id !== null ? $this->intParam($id) : null);
        return $this->redirectRoute('products.tax', ['tab' => 'masters'])->with('success', 'Tax category saved.');
    }

    public function saveCode(Request $request, ?string $id = null): Response
    {
        (new TaxConfigService(App::db()))->saveCode($request->all() + ($id === null ? ['is_active' => 1] : []), $this->user(), $id !== null ? $this->intParam($id) : null);
        return $this->redirectRoute('products.tax', ['tab' => 'masters'])->with('success', 'HSN / SAC code saved.');
    }

    public function mappings(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'status', 'preferred'], ['supplier']);
        $sql = "SELECT m.*, p.product_code, p.name AS product_name, v.name AS variant_name, s.supplier_code, s.name AS supplier_name FROM product_supplier_mappings m JOIN products p ON p.id = m.product_id
            LEFT JOIN product_variants v ON v.id = m.variant_id JOIN suppliers s ON s.id = m.supplier_id WHERE {$w}";
        $params = $p;
        // Only pairs whose supplier is in the user's supplier scope.
        [$sw, $sp] = can('suppliers.view') ? App::dataAccess()->constrain($this->user(), 'suppliers', 's') : ['1 = 0', []];
        $sql .= " AND {$sw}";
        $params = array_merge($params, $sp);
        $sql .= $f['status'] === 'all' ? '' : ($f['status'] === 'ended' ? " AND m.status = 'ended'" : " AND m.status <> 'ended'");
        if ($f['preferred'] === '1') {
            $sql .= ' AND m.is_preferred = 1';
        }
        if ((int) $f['supplier'] > 0) {
            $sql .= ' AND m.supplier_id = ?';
            $params[] = (int) $f['supplier'];
        }
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(p.name) LIKE ? OR LOWER(p.product_code) LIKE ? OR LOWER(s.name) LIKE ? OR LOWER(m.supplier_sku) LIKE ? OR LOWER(m.supplier_product_code) LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }
        $page = max(1, (int) $request->input('page', 1));
        $per = 40;
        $total = (int) App::db()->scalar("SELECT COUNT(*) FROM ({$sql}) x", $params);
        $rows = App::db()->select($sql . ' ORDER BY p.name, v.name, m.is_preferred DESC, m.priority LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
        $pricing = new PricingService(App::db());
        foreach ($rows as &$r) {
            $price = $pricing->priceOn((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, 'supplier_purchase', BusinessDate::today(), (int) $r['supplier_id']);
            $r['purchase_price'] = $price !== null ? Money::fromDb($price['amount']) : null;
        }
        return $this->view('products/mappings', ['title' => 'Product-supplier mappings', 'breadcrumbs' => self::CRUMB + ['Supplier mappings' => null], 'rows' => new Paginator($rows, $total, $page, $per),
            'f' => $f, 'suppliers' => $this->supplierOptions(), 'fields' => App::fields()->accessMap($this->user(), 'products')]);
    }

    public function documents(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'category', 'expiring'], []);
        $sql = "SELECT d.*, p.product_code, p.name AS product_name, u.name AS uploaded_by_name FROM product_documents d JOIN products p ON p.id = d.product_id LEFT JOIN users u ON u.id = d.uploaded_by
            WHERE {$w} AND d.status = 'active'";
        $params = $p;
        if (isset(ProductMediaService::CATEGORIES[$f['category']])) {
            $sql .= ' AND d.category = ?';
            $params[] = $f['category'];
        }
        if ($f['expiring'] === '1') {
            $sql .= ' AND d.expiry_date IS NOT NULL AND d.expiry_date <= ?';
            $params[] = BusinessDate::addDays(BusinessDate::today(), 60);
        }
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(d.title) LIKE ? OR LOWER(d.original_name) LIKE ? OR LOWER(p.name) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        return $this->view('products/documents', ['title' => 'Product documents', 'breadcrumbs' => self::CRUMB + ['Documents' => null],
            'rows' => App::db()->select($sql . ' ORDER BY d.created_at DESC LIMIT 300', $params), 'f' => $f, 'categories' => ProductMediaService::CATEGORIES]);
    }

    // ---------------------------------------------------------------- settings

    public function settings(Request $request): Response
    {
        $defs = array_filter(SettingsService::definitions(), static fn (array $d): bool => ($d['group'] ?? '') === 'products');
        return $this->view('products/settings', ['title' => 'Product & supplier settings', 'breadcrumbs' => self::CRUMB + ['Settings' => null],
            'defs' => $defs, 'values' => App::settings()->all(), 'types' => $this->catalog->productTypes(false), 'edit' => (int) $request->input('edit', 0),
            'workflows' => App::db()->select("SELECT w.id, w.workflow_key, w.name, w.is_active, m.module_key FROM approval_workflows w JOIN modules m ON m.id = w.module_id WHERE m.module_key IN ('products', 'suppliers') ORDER BY m.module_key, w.name")]);
    }

    public function saveRules(Request $request): Response
    {
        $defs = array_filter(SettingsService::definitions(), static fn (array $d): bool => ($d['group'] ?? '') === 'products');
        $input = [];
        foreach ($defs as $k => $d) {
            $input[$k] = $d['type'] === 'boolean' ? ($request->string($k) === '1' ? '1' : '0') : $request->string($k);
        }
        $changed = App::settings()->update($input, (int) $this->user()['id'], ['products']);
        if ($changed !== []) {
            App::activity()->log('product.settings_changed', 'Changed product settings: ' . implode(', ', $changed), module: 'products', newValues: array_intersect_key($input, array_flip($changed)));
        }
        return $this->redirectRoute('products.settings')->with('success', $changed === [] ? 'Nothing changed.' : 'Settings saved.');
    }

    public function saveType(Request $request, ?string $id = null): Response
    {
        $this->catalog->saveProductType($request->all() + ($id === null ? ['is_active' => 1] : []), $this->user(), $id !== null ? $this->intParam($id) : null);
        return $this->redirectRoute('products.settings')->with('success', 'Product type saved.');
    }
}
