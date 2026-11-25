<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Bank\BankAccountService;
use App\Services\Export\TableExport;
use App\Services\Product\DatedMappingService;
use App\Services\Product\SupplierMappingService;
use App\Services\Supplier\SupplierBankService;
use App\Services\Supplier\SupplierDashboardService;
use App\Services\Supplier\SupplierDetailService;
use App\Services\Supplier\SupplierReportService;
use App\Services\Supplier\SupplierService;

/** Supplier dashboard, list, add/edit, the 10-tab supplier page and lifecycle actions. */
final class SupplierController extends SupplierBaseController
{
    public const TABS = [
        'overview' => ['Overview', null], 'business' => ['Business information', null], 'contact' => ['Contact information', null], 'addresses' => ['Addresses', null],
        'gst' => ['GST details', null], 'bank' => ['Bank details', 'suppliers.view_bank_details'], 'products' => ['Products', null], 'activities' => ['Business activities', null],
        'documents' => ['Documents', null], 'history' => ['History', 'suppliers.view_history'],
    ];
    private const FILTER_TEXT = ['q', 'status', 'archived', 'sort', 'treatment', 'linked'];
    private const FILTER_INTS = ['type', 'state', 'gst', 'activity', 'product'];
    /** Fields protected by field rules: users who may not edit them keep the stored value. */
    private const PROTECTED = ['contact_person' => 'contact_person', 'mobile' => 'contact_phone', 'email' => 'contact_email', 'pan' => 'pan', 'gstin' => 'gstin',
        'payment_terms' => 'payment_terms', 'credit_period_days' => 'payment_terms', 'internal_notes' => 'internal_notes'];

    private SupplierService $suppliers;

    public function __construct()
    {
        $this->suppliers = new SupplierService(App::db());
    }

    public function dashboard(Request $request): Response
    {
        $f = $this->filters($request, ['status', 'treatment'], ['type', 'state', 'gst', 'activity']);
        return $this->view('suppliers/dashboard', ['title' => 'Supplier Management', 'breadcrumbs' => ['Suppliers' => null],
            'd' => (new SupplierDashboardService(App::db()))->data($f, $this->scope()), 'f' => $f, 'scopeLabel' => App::dataAccess()->resolve($this->user(), 'suppliers')->describe(),
            'types' => $this->suppliers->types(false), 'states' => $this->suppliers->states(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions()]);
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, self::FILTER_TEXT, self::FILTER_INTS);
        return $this->view('suppliers/index', ['title' => 'Suppliers', 'breadcrumbs' => self::CRUMB + ['All suppliers' => null],
            'suppliers' => $this->suppliers->paginate($f, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15), $this->scope()), 'f' => $f,
            'types' => $this->suppliers->types(false), 'states' => $this->suppliers->states(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(),
            'fields' => App::fields()->accessMap($this->user(), 'suppliers')]);
    }

    public function export(Request $request): Response
    {
        $f = $this->filters($request, self::FILTER_TEXT, self::FILTER_INTS);
        $result = (new SupplierReportService(App::db()))->run($this->user(), 'suppliers', $f);
        $format = $request->string('format', 'csv');
        App::activity()->log('supplier.list_exported', "Exported the supplier list as {$format} (" . count($result['rows']) . ' rows)', module: 'suppliers', newValues: ['format' => $format, 'filters' => array_filter($f)]);
        return (new TableExport('Suppliers', $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'suppliers');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $me = $this->user();
        $input = $request->all();
        $protected = $this->protectedInput($input);
        if (trim((string) ($input['addr_line1'] ?? '')) !== '') {
            $protected['addresses'] = $input['addr_line1'];
        }
        App::fields()->assertWritable($me, 'suppliers', $protected);
        $submit = $request->string('action') === 'submit';
        // The supplier and its business address are saved together, so a bad address leaves nothing half-saved.
        [$id, $change] = App::db()->transaction(function () use ($input, $me, $submit): array {
            [$id] = $this->suppliers->create($input, $me, false);
            if (trim((string) ($input['addr_line1'] ?? '')) !== '') {
                (new SupplierDetailService(App::db()))->saveAddress($this->suppliers->find($id), ['address_type' => 'business', 'line1' => $input['addr_line1'], 'line2' => $input['addr_line2'] ?? '',
                    'city' => $input['addr_city'] ?? '', 'state_id' => $input['addr_state_id'] ?? '', 'state_name' => $input['addr_state_name'] ?? '', 'pin_code' => $input['addr_pin_code'] ?? '',
                    'country' => $input['country'] ?? 'IN', 'is_default' => 1], $me);
            }
            return [$id, $submit ? $this->suppliers->submit($this->suppliers->find($id), $me) : null];
        });
        $msg = match (true) {
            $change === null => 'Draft saved. Add addresses, documents and bank details, then submit it for approval.',
            $change['status'] === 'approved' => 'Supplier added and active.',
            default => 'Supplier added and sent for approval. It becomes active once approved.',
        };
        return $this->redirectRoute('suppliers.show', ['id' => $id])->with('success', $msg);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $s = $this->findSupplier($id);
        $sid = (int) $s['id'];
        $tabs = array_filter(self::TABS, static fn (array $t): bool => $t[1] === null || can($t[1]));
        $tab = $request->string('tab', 'overview');
        if (!isset($tabs[$tab])) {
            $tab = 'overview';
        }
        $db = App::db();
        $detail = new SupplierDetailService($db);
        $maps = new SupplierMappingService($db);
        $productScope = can('products.view') ? App::dataAccess()->constrain($me, 'products', 'p') : ['1 = 0', []];
        $data = [
            'title' => $s['name'], 'breadcrumbs' => self::CRUMB + ['All suppliers' => route('suppliers.index'), $s['supplier_code'] => null],
            's' => $s, 'tab' => $tab, 'tabs' => array_map(static fn (array $t): string => $t[0], $tabs), 'fields' => App::fields()->accessMap($me, 'suppliers'),
            'productFields' => App::fields()->accessMap($me, 'products'),
            'pending' => array_values(array_filter($this->suppliers->changes($sid), static fn (array $c): bool => $c['status'] === 'pending')),
            'transitions' => array_filter(SupplierService::TRANSITIONS, static fn (array $t): bool => in_array($s['status'], $t[0], true) && can('suppliers.' . $t[1])),
            'counts' => [
                'products' => (int) $db->scalar("SELECT COUNT(DISTINCT product_id) FROM product_supplier_mappings WHERE supplier_id = ? AND status = 'active'", [$sid]),
                'addresses' => (int) $db->scalar("SELECT COUNT(*) FROM supplier_addresses WHERE supplier_id = ? AND status = 'active'", [$sid]),
                'documents' => (int) $db->scalar("SELECT COUNT(*) FROM supplier_documents WHERE supplier_id = ? AND status = 'active'", [$sid]),
                'bank' => (int) $db->scalar("SELECT COUNT(*) FROM supplier_bank_details WHERE supplier_id = ? AND status = 'active'", [$sid]),
                'gst' => (int) $db->scalar("SELECT COUNT(*) FROM supplier_gst_mappings WHERE supplier_id = ? AND status = 'active'", [$sid]),
            ],
            'businessAddress' => $detail->addresses($sid)[0] ?? null,
        ];
        $data += match ($tab) {
            'overview' => ['products' => array_slice($maps->forSupplier($sid, $productScope, false), 0, 8), 'recent' => array_slice($this->suppliers->historyFor($sid), 0, 6)],
            'addresses' => ['addresses' => $detail->addresses($sid, $request->string('archived') === '1'), 'states' => $this->suppliers->states(), 'addressTypes' => SupplierDetailService::ADDRESS_TYPES,
                'edit' => (int) $request->input('edit', 0), 'showArchived' => $request->string('archived') === '1'],
            'gst' => ['mappings' => (new DatedMappingService($db, 'supplier_gst'))->list($sid), 'gstOptions' => $this->gstOptions()],
            'bank' => ['details' => (new SupplierBankService($db))->forSupplier($sid), 'accountTypes' => SupplierBankService::ACCOUNT_TYPES,
                'links' => $detail->paymentLinks($sid, $this->bankScope()), 'linkable' => can('suppliers.link_bank_transactions') && $this->bankScope() !== null ? $detail->linkableTransactions($this->bankScope()) : []],
            'products' => ['mappings' => $maps->forSupplier($sid, $productScope), 'productOptions' => can('suppliers.manage_product_mappings') ? $this->productOptions() : []],
            'activities' => ['mappings' => (new DatedMappingService($db, 'supplier_activity'))->list($sid), 'activityOptions' => $this->activityOptions()],
            'documents' => ['documents' => $detail->documents($sid, $request->string('archived') === '1', can('suppliers.view_bank_details')), 'docCategories' => SupplierDetailService::DOC_CATEGORIES,
                'showArchived' => $request->string('archived') === '1'],
            'history' => ['history' => $this->suppliers->historyFor($sid), 'changes' => $this->suppliers->changes($sid),
                'audit' => $db->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.subject_type = 'supplier' AND l.subject_id = ? ORDER BY l.id DESC LIMIT 150", [$sid])],
            default => [],
        };
        return $this->view('suppliers/show', $data);
    }

    public function edit(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        $this->suppliers->assertWritable($s);
        return $this->form($s);
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->user();
        $s = $this->findSupplier($id);
        $input = $request->all();
        foreach (self::PROTECTED as $key => $field) {
            if (!App::fields()->access($me, 'suppliers', $field)->canEdit() || !array_key_exists($key, $input)) {
                $input[$key] = $s[$key];
            }
        }
        foreach (array_merge(SupplierService::GST_FIELDS, SupplierService::DIRECT_FIELDS) as $k) {
            if (!array_key_exists($k, $input)) {
                $input[$k] = $s[$k];
            }
        }
        if (!can('suppliers.manage_gst_details')) {
            foreach (['gstin', 'pan', 'gst_treatment', 'legal_name'] as $k) {
                $input[$k] = $s[$k];
            }
        }
        $r = $this->suppliers->update($s, $input, $me, $request->string('change_reason') ?: null);
        $waiting = $r['key'] !== [] && ($r['change']['status'] ?? '') !== 'approved';
        $msg = match (true) {
            $waiting => 'GST details sent for approval. They change once approved.' . ($r['direct'] !== [] ? ' Other changes are saved.' : ''),
            $r['direct'] === [] && $r['key'] === [] => 'Nothing changed.',
            default => 'Supplier updated.',
        };
        return $this->backTo((int) $s['id'], 'business', $msg, $waiting ? 'info' : 'success');
    }

    public function submit(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        $change = $this->suppliers->submit($s, $this->user());
        return $this->backTo((int) $s['id'], 'overview', $change['status'] === 'approved' ? 'Supplier approved and active.' : 'Supplier sent for approval.', $change['status'] === 'approved' ? 'success' : 'info');
    }

    public function status(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        $data = $this->validate($request, ['status' => 'required|in:active,inactive,blocked', 'reason' => 'nullable|string|max:255']);
        $this->authorize('suppliers.' . SupplierService::TRANSITIONS[$data['status']][1]);
        $this->suppliers->changeStatus($s, (string) $data['status'], $data['reason'] ?? null, $this->user());
        return $this->backTo((int) $s['id'], 'overview', 'Status changed to ' . strtolower(SupplierService::STATUSES[$data['status']]) . '.');
    }

    public function archive(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        $data = $this->validate($request, ['reason' => 'required|string|max:255']);
        $change = $this->suppliers->requestArchive($s, (string) $data['reason'], $this->user());
        return $this->backTo((int) $s['id'], 'overview', $change['status'] === 'approved' ? 'Supplier archived. It is read-only; its history is kept.' : 'Archiving sent for approval.', $change['status'] === 'approved' ? 'success' : 'info');
    }

    public function restore(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        $this->suppliers->restore($s, $this->user());
        return $this->backTo((int) $s['id'], 'overview', 'Supplier restored.');
    }

    /** Live GSTIN check for the supplier form (format only; no portal lookup). */
    public function checkGstin(Request $request): Response
    {
        return Response::json($this->suppliers->checkGstin($request->string('gstin'), $request->string('pan') ?: null, (int) $request->input('except', 0) ?: null));
    }

    /** @param array<string, mixed>|null $s */
    private function form(?array $s): Response
    {
        return $this->view('suppliers/form', [
            'title' => $s === null ? 'Add supplier' : 'Edit ' . $s['name'],
            'breadcrumbs' => self::CRUMB + ['All suppliers' => route('suppliers.index')] + ($s === null ? ['Add' => null] : [$s['supplier_code'] => route('suppliers.show', ['id' => $s['id']]), 'Edit' => null]),
            's' => $s, 'types' => $this->suppliers->types(), 'treatments' => SupplierService::GST_TREATMENTS, 'currencies' => BankAccountService::CURRENCIES,
            'fields' => App::fields()->accessMap($this->user(), 'suppliers'), 'gstFields' => SupplierService::GST_FIELDS, 'states' => $this->suppliers->states(),
            'addressTypes' => SupplierDetailService::ADDRESS_TYPES,
        ]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> non-empty protected values, keyed by field rule */
    private function protectedInput(array $input): array
    {
        $out = [];
        foreach (self::PROTECTED as $key => $field) {
            if (trim((string) ($input[$key] ?? '')) !== '') {
                $out[$field] = $input[$key];
            }
        }
        return $out;
    }
}
