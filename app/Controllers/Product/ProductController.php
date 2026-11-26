<?php

declare(strict_types=1);

namespace App\Controllers\Product;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export\TableExport;
use App\Services\Product\CatalogService;
use App\Services\Product\DatedMappingService;
use App\Services\Product\PricingService;
use App\Services\Product\ProductDashboardService;
use App\Services\Product\ProductMediaService;
use App\Services\Product\ProductReportService;
use App\Services\Product\ProductService;
use App\Services\Product\SkuService;
use App\Services\Product\SupplierMappingService;
use App\Services\Product\TaxConfigService;
use App\Services\Product\VariantService;
use App\Support\Barcode;
use App\Support\BusinessDate;

/** Product dashboard, list, add/edit, the 10-tab product page and lifecycle actions. */
final class ProductController extends ProductBaseController
{
    /** tab => [label, permission or null] */
    public const TABS = [
        'overview' => ['Overview', null], 'information' => ['Basic information', null], 'variants' => ['Variants', null], 'pricing' => ['Pricing', null],
        'tax' => ['GST / HSN', null], 'suppliers' => ['Suppliers', null], 'gst' => ['GST registrations', null], 'activities' => ['Business activities', null],
        'media' => ['Images & documents', null], 'history' => ['History', 'products.view_history'],
    ];
    private const FILTER_TEXT = ['q', 'status', 'archived', 'sort', 'missing', 'hsn'];
    private const FILTER_INTS = ['type', 'category', 'brand', 'gst', 'activity', 'supplier'];

    private ProductService $products;

    public function __construct()
    {
        $this->products = new ProductService(App::db());
    }

    public function dashboard(Request $request): Response
    {
        $f = $this->filters($request, ['status', 'hsn'], ['type', 'category', 'brand', 'gst', 'activity', 'supplier']);
        return $this->view('products/dashboard', [
            'title' => 'Product Management', 'breadcrumbs' => ['Products' => null],
            'd' => (new ProductDashboardService(App::db()))->data($f, $this->scope()), 'f' => $f,
            'scopeLabel' => App::dataAccess()->resolve($this->user(), 'products')->describe(),
            'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(), 'suppliers' => $this->supplierOptions(),
        ] + $this->masters());
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, self::FILTER_TEXT, self::FILTER_INTS);
        return $this->view('products/index', [
            'title' => 'Products', 'breadcrumbs' => self::CRUMB + ['All products' => null],
            'products' => $this->products->paginate($f, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15), $this->scope()),
            'f' => $f, 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(), 'suppliers' => $this->supplierOptions(),
        ] + $this->masters());
    }

    public function export(Request $request): Response
    {
        $f = $this->filters($request, self::FILTER_TEXT, self::FILTER_INTS);
        $result = (new ProductReportService(App::db()))->run($this->user(), 'products', $f);
        $format = $request->string('format', 'csv');
        App::activity()->log('product.list_exported', "Exported the product list as {$format} (" . count($result['rows']) . ' rows)', module: 'products', newValues: ['format' => $format, 'filters' => array_filter($f)]);
        return (new TableExport('Products', $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'products');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $me = $this->user();
        App::fields()->assertWritable($me, 'products', array_filter(array_intersect_key($request->all(), ['internal_notes' => 1]), static fn ($v): bool => trim((string) $v) !== ''));
        [$id, $change] = $this->products->create($request->all(), $me, $request->string('action') === 'submit');
        $msg = match (true) {
            $change === null => 'Draft saved. Add variants, prices and GST details, then submit it for approval.',
            $change['status'] === 'approved' => 'Product added and active.',
            default => 'Product added and sent for approval. It becomes active once approved.',
        };
        return $this->redirectRoute('products.show', ['id' => $id])->with('success', $msg);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $p = $this->findProduct($id);
        $pid = (int) $p['id'];
        $tabs = array_filter(self::TABS, static fn (array $t): bool => $t[1] === null || can($t[1]));
        $tab = $request->string('tab', 'overview');
        if (!isset($tabs[$tab])) {
            $tab = 'overview';
        }
        $db = App::db();
        $variants = new VariantService($db);
        $pricing = new PricingService($db);
        $tax = new TaxConfigService($db);
        $maps = new SupplierMappingService($db);
        $media = new ProductMediaService($db);
        $fields = App::fields()->accessMap($me, 'products');
        $variantRows = $variants->forProduct($pid);
        $data = [
            'title' => $p['name'], 'breadcrumbs' => self::CRUMB + ['All products' => route('products.index'), $p['product_code'] => null],
            'p' => $p, 'tab' => $tab, 'tabs' => array_map(static fn (array $t): string => $t[0], $tabs), 'fields' => $fields,
            'categoryPath' => (new CatalogService($db))->categoryPath($p['subcategory_id'] !== null ? (int) $p['subcategory_id'] : ($p['category_id'] !== null ? (int) $p['category_id'] : null)),
            'pending' => $this->products->pendingChanges($pid), 'variantRows' => $variantRows,
            'primaryImage' => $db->scalar("SELECT id FROM product_images WHERE product_id = ? AND variant_id IS NULL AND status = 'active' ORDER BY is_primary DESC, sort_order, id LIMIT 1", [$pid]),
            'transitions' => array_filter(ProductService::TRANSITIONS, static fn (array $t): bool => in_array($p['status'], $t[0], true) && can('products.' . $t[1])),
            'counts' => [
                'variants' => count(array_filter($variantRows, static fn (array $v): bool => $v['status'] !== 'archived')),
                'suppliers' => (int) $db->scalar("SELECT COUNT(*) FROM product_supplier_mappings WHERE product_id = ? AND status = 'active'", [$pid]),
                'gst' => (int) $db->scalar("SELECT COUNT(*) FROM product_gst_mappings WHERE product_id = ? AND status = 'active'", [$pid]),
                'activities' => (int) $db->scalar("SELECT COUNT(*) FROM product_business_mappings WHERE product_id = ? AND status = 'active'", [$pid]),
                'images' => (int) $db->scalar("SELECT COUNT(*) FROM product_images WHERE product_id = ? AND status = 'active'", [$pid]),
                'documents' => (int) $db->scalar("SELECT COUNT(*) FROM product_documents WHERE product_id = ? AND status = 'active'", [$pid]),
            ],
        ];
        $mappedGst = fn (): array => array_values(array_filter($this->gstOptions(), static fn (array $g): bool => in_array((int) $g['id'], (new DatedMappingService(App::db(), 'product_gst'))->activeTargetIds($pid), true)));
        $data += match ($tab) {
            'overview' => ['current' => $pricing->current($pid), 'taxNow' => $tax->resolve($pid, null, null), 'preferred' => ($pref = $maps->preferredFor($pid, null)) !== null ? $this->redactSuppliers([$pref])[0] : null,
                'recent' => array_slice($this->products->historyFor($pid), 0, 6), 'components' => $p['has_components'] ? $variants->components($pid) : [], 'usedIn' => $variants->usedIn($pid)],
            'information' => ['components' => $variants->components($pid), 'usedIn' => $variants->usedIn($pid), 'skuHistory' => (new SkuService($db))->historyFor($pid),
                'productOptions' => (int) $p['has_components'] && can('products.edit') ? $this->productOptions($pid) : []],
            'variants' => ['attributes' => (new CatalogService($db))->attributes(true)],
            'pricing' => ['current' => $this->redactSuppliers($pricing->current($pid)), 'upcoming' => $this->redactSuppliers($pricing->upcoming($pid)),
                'priceHistory' => $this->redactSuppliers($pricing->history($pid, $this->filters($request, ['type', 'status'], ['variant', 'supplier']))), 'hf' => $this->filters($request, ['type', 'status'], ['variant', 'supplier']),
                'mappedSuppliers' => $this->redactSuppliers($maps->forProduct($pid, false), true), 'mappedGst' => $mappedGst()],
            'tax' => ['configs' => $tax->forProduct($pid), 'currentTax' => $tax->current($pid), 'rates' => $tax->rates(), 'taxCategories' => $tax->categories(true),
                'codes' => $tax->codes('', true), 'mappedGst' => $mappedGst()],
            'suppliers' => ['mappings' => $this->redactSuppliers($maps->forProduct($pid), true), 'supplierOptions' => $this->supplierOptions()],
            'gst' => ['mappings' => (new DatedMappingService($db, 'product_gst'))->list($pid), 'gstOptions' => $this->gstOptions()],
            'activities' => ['mappings' => (new DatedMappingService($db, 'product_activity'))->list($pid), 'activityOptions' => $this->activityOptions()],
            'media' => ['images' => $media->images($pid, $request->string('archived') === '1'), 'documents' => can('products.download_documents') || can('products.manage_documents') ? $media->documents($pid, $request->string('archived') === '1') : [],
                'docCategories' => ProductMediaService::CATEGORIES, 'showArchived' => $request->string('archived') === '1'],
            'history' => ['history' => $this->products->historyFor($pid), 'changes' => $this->products->changes($pid),
                'audit' => $db->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.subject_type = 'product' AND l.subject_id = ? ORDER BY l.id DESC LIMIT 150", [$pid])],
            default => [],
        };
        return $this->view('products/show', $data);
    }

    public function edit(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $this->products->assertWritable($p);
        return $this->form($p);
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->user();
        $p = $this->findProduct($id);
        $input = $request->all();
        if (!App::fields()->access($me, 'products', 'internal_notes')->canEdit()) {
            unset($input['internal_notes']);
        }
        // Fields left out of the form keep their stored value.
        foreach (array_merge(ProductService::KEY_FIELDS, ProductService::DIRECT_FIELDS) as $k) {
            if (!array_key_exists($k, $input)) {
                $input[$k] = $p[$k];
            }
        }
        $r = $this->products->update($p, $input, $me, $request->string('change_reason') ?: null);
        $waiting = $r['key'] !== [] && ($r['change']['status'] ?? '') !== 'approved';
        $msg = match (true) {
            $waiting => 'Key details sent for approval: ' . implode(', ', array_map(static fn (string $k): string => str_replace(['_id', '_'], ['', ' '], $k), $r['key'])) . '. They change once approved.' . ($r['direct'] !== [] ? ' Other changes are saved.' : ''),
            $r['direct'] === [] && $r['key'] === [] => 'Nothing changed.',
            default => 'Product updated.',
        };
        return $this->backTo((int) $p['id'], 'information', $msg, $waiting ? 'info' : 'success');
    }

    public function submit(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $change = $this->products->submit($p, $this->user());
        return $this->backTo((int) $p['id'], 'overview', $change['status'] === 'approved' ? 'Product approved and active.' : 'Product sent for approval.', $change['status'] === 'approved' ? 'success' : 'info');
    }

    public function status(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $data = $this->validate($request, ['status' => 'required|in:active,inactive,discontinued', 'reason' => 'nullable|string|max:255']);
        $this->authorize('products.' . ProductService::TRANSITIONS[$data['status']][1]);
        $this->products->changeStatus($p, (string) $data['status'], $data['reason'] ?? null, $this->user());
        return $this->backTo((int) $p['id'], 'overview', 'Status changed to ' . strtolower(ProductService::STATUSES[$data['status']]) . '.');
    }

    public function sku(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $variant = (int) $request->input('variant_id', 0) ?: null;
        $change = $this->products->requestSku($p, $variant, $request->string('sku'), $request->string('reason'), $this->user());
        $tab = $variant !== null ? 'variants' : 'information';
        if ($change === null || $change['status'] === 'approved') {
            return $this->backTo((int) $p['id'], $tab, 'SKU changed. The old SKU stays reserved so older records remain unambiguous.');
        }
        return $this->backTo((int) $p['id'], $tab, 'SKU change sent for approval.', 'info');
    }

    public function archive(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $data = $this->validate($request, ['reason' => 'required|string|max:255']);
        $change = $this->products->requestArchive($p, (string) $data['reason'], $this->user());
        return $this->backTo((int) $p['id'], 'overview', $change['status'] === 'approved' ? 'Product archived. It is read-only and cannot be used in new transactions; its history is kept.' : 'Archiving sent for approval.',
            $change['status'] === 'approved' ? 'success' : 'info');
    }

    public function restore(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $this->products->restore($p, $this->user());
        return $this->backTo((int) $p['id'], 'overview', 'Product restored.');
    }

    public function archivedUse(Request $request, string $id): Response
    {
        $p = $this->findProduct($id);
        $data = $this->validate($request, ['allowed' => 'required|in:0,1', 'reason' => 'required|string|max:255']);
        $this->products->setArchivedUse($p, $data['allowed'] === '1', (string) $data['reason'], $this->user());
        return $this->backTo((int) $p['id'], 'overview', $data['allowed'] === '1' ? 'This archived product may now be used in new transactions.' : 'This archived product can no longer be used in new transactions.');
    }

    /** Recent changes across all products the user may see. */
    public function historyPage(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['event', 'from', 'to'], []);
        $sql = "SELECT h.*, pr.product_code, pr.name AS product_name, v.name AS variant_name, u.name AS actor_name FROM product_history h JOIN products pr ON pr.id = h.product_id
            LEFT JOIN product_variants v ON v.id = h.variant_id LEFT JOIN users u ON u.id = h.actor_id WHERE h.product_id IN (SELECT p.id FROM products p WHERE {$w})";
        $params = $p;
        if ($f['event'] !== '') {
            $sql .= ' AND h.event LIKE ?';
            $params[] = $f['event'] . '%';
        }
        if ($f['from'] !== '') {
            $sql .= ' AND h.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            $sql .= ' AND h.created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        $page = max(1, (int) $request->input('page', 1));
        $total = (int) App::db()->scalar("SELECT COUNT(*) FROM ({$sql}) x", $params);
        $rows = App::db()->select($sql . ' ORDER BY h.id DESC LIMIT 40 OFFSET ' . (($page - 1) * 40), $params);
        foreach ($rows as &$r) {
            $r['old_values'] = $r['old_values'] !== null ? json_decode((string) $r['old_values'], true) : null;
            $r['new_values'] = $r['new_values'] !== null ? json_decode((string) $r['new_values'], true) : null;
        }
        return $this->view('products/history', ['title' => 'Product history', 'breadcrumbs' => self::CRUMB + ['History' => null], 'rows' => new Paginator($rows, $total, $page, 40), 'f' => $f]);
    }

    /** @param array<string, mixed>|null $p */
    private function form(?array $p): Response
    {
        return $this->view('products/form', [
            'title' => $p === null ? 'Add product' : 'Edit ' . $p['name'],
            'breadcrumbs' => self::CRUMB + ['All products' => route('products.index')] + ($p === null ? ['Add' => null] : [$p['product_code'] => route('products.show', ['id' => $p['id']]), 'Edit' => null]),
            'p' => $p, 'units' => ProductService::units(), 'barcodeTypes' => Barcode::TYPES, 'keyFields' => ProductService::KEY_FIELDS,
            'fields' => App::fields()->accessMap($this->user(), 'products'), 'skuAuto' => (bool) setting('product_sku_auto_generate', true), 'skuManual' => (bool) setting('product_sku_manual_allowed', true),
            'skuPattern' => (string) setting('product_sku_pattern', '{CAT}-{SEQ:5}'), 'today' => BusinessDate::today(),
        ] + $this->masters(true));
    }
}
