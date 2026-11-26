<?php

declare(strict_types=1);

namespace App\Controllers\Ecommerce;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Ecommerce\PlatformCategoryService;
use App\Services\Ecommerce\PlatformDashboardService;
use App\Services\Ecommerce\PlatformDocumentService;
use App\Services\Ecommerce\PlatformHistory;
use App\Services\Ecommerce\PlatformNoteService;
use App\Services\Ecommerce\PlatformScope;
use App\Services\Ecommerce\PlatformService;
use App\Services\Export\TableExport;
use App\Services\Inventory\WarehouseService;
use App\Services\Product\DatedMappingService;
use App\Support\Locale;

/**
 * E-commerce platform master (Phase 8A): dashboard, list, add / edit, the platform page with its tabs,
 * status changes, archive / restore, logo, configuration, mappings, documents, notes, history, categories
 * and export. Every platform is loaded through the record scope (404 outside it).
 */
final class PlatformController extends Controller
{
    public const TABS = [
        'overview' => 'Overview', 'configuration' => 'Configuration', 'gst' => 'GST mappings', 'business' => 'Business mappings', 'warehouses' => 'Warehouse mappings',
        'accounts' => 'Seller accounts', 'documents' => 'Documents', 'notes' => 'Notes', 'history' => 'History',
    ];
    private const CRUMB = ['E-commerce' => '/ecommerce'];
    /** mapping kind => [permission, tab, target input] */
    private const MAPPINGS = [
        'gst' => ['platform_gst', 'platforms.manage_gst_mappings', 'gst', 'gst_registration_id'],
        'activities' => ['platform_activity', 'platforms.manage_business_mappings', 'business', 'business_activity_id'],
        'partners' => ['platform_partner', 'platforms.manage_business_mappings', 'business', 'partner_id'],
        'warehouses' => ['platform_warehouse', 'platforms.manage_warehouse_mappings', 'warehouses', 'warehouse_id'],
    ];

    private PlatformService $platforms;

    public function __construct()
    {
        $this->platforms = new PlatformService(App::db());
    }

    public function dashboard(Request $request): Response
    {
        $this->authorize('platforms.view_dashboard');
        $f = $this->filters($request);
        return $this->view('ecommerce/dashboard', ['title' => 'E-commerce dashboard', 'breadcrumbs' => self::CRUMB + ['Dashboard' => null], 'f' => $f,
            'd' => (new PlatformDashboardService(App::db()))->data($this->user(), $f), 'categories' => (new PlatformCategoryService(App::db()))->all(),
            'options' => $this->platforms->options($this->user(), true)]);
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request);
        return $this->view('ecommerce/platforms/index', ['title' => 'E-commerce platforms', 'breadcrumbs' => self::CRUMB + ['Platforms' => null], 'f' => $f,
            'rows' => $this->platforms->list($this->user(), $f), 'categories' => (new PlatformCategoryService(App::db()))->all(), 'gsts' => $this->gstOptions()]);
    }

    public function export(Request $request): Response
    {
        $f = $this->filters($request);
        $notes = App::fields()->access($this->user(), 'platforms', 'internal_notes')->canView();
        $web = App::fields()->access($this->user(), 'platforms', 'website_url')->canView();
        $columns = ['platform_code' => ['label' => 'Code'], 'name' => ['label' => 'Platform'], 'category_name' => ['label' => 'Category']]
            + ($web ? ['website_url' => ['label' => 'Website']] : [])
            + ['country' => ['label' => 'Country'], 'currencies' => ['label' => 'Currencies'], 'default_currency' => ['label' => 'Default currency'], 'languages' => ['label' => 'Languages'],
                'status' => ['label' => 'Status'], 'connection' => ['label' => 'Connection'], 'configuration' => ['label' => 'Configuration'], 'gst_count' => ['label' => 'GST registrations', 'type' => 'number'],
                'activity_count' => ['label' => 'Business activities', 'type' => 'number'], 'warehouse_count' => ['label' => 'Warehouses', 'type' => 'number'], 'created_at' => ['label' => 'Added on']]
            + ($notes ? ['internal_notes' => ['label' => 'Internal notes']] : []);
        $rows = [];
        foreach ($this->platforms->list($this->user(), $f) as $r) {
            $rows[] = ['platform_code' => $r['platform_code'], 'name' => $r['name'], 'category_name' => $r['category_name'], 'website_url' => (string) $r['website_url'],
                'country' => Locale::COUNTRIES[$r['country']] ?? $r['country'], 'currencies' => $r['currencies'], 'default_currency' => $r['default_currency'], 'languages' => Locale::names((string) $r['languages'], Locale::LANGUAGES),
                'status' => PlatformService::STATUSES[$r['status']], 'connection' => PlatformService::CONNECTION[$r['connection_status']], 'configuration' => $r['readiness']['percent'] . '% complete',
                'gst_count' => (int) $r['gst_count'], 'activity_count' => (int) $r['activity_count'], 'warehouse_count' => (int) $r['warehouse_count'], 'created_at' => substr((string) $r['created_at'], 0, 10),
                'internal_notes' => (string) $r['internal_notes']];
        }
        App::activity()->log('platforms.exported', 'Exported the platform list (' . count($rows) . ' rows)', null, null, module: 'platforms');
        $format = $request->string('format', 'csv');
        return (new TableExport('E-commerce platforms', $columns, $rows, ['Generated ' . gmdate('Y-m-d H:i') . ' UTC by ' . $this->user()['name']]))
            ->response(isset(TableExport::FORMATS[$format]) ? $format : 'csv', 'ecommerce-platforms');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $input = $this->protect($request->all(), null);
        $id = $this->platforms->create($input, $this->user());
        $logo = $request->file('logo');
        if ($logo !== null && ($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->platforms->setLogo($this->platforms->find($id), $logo, $this->user());
        }
        return $this->redirectRoute('ecommerce.platforms.show', ['id' => $id, 'tab' => 'configuration'])
            ->with('success', 'Platform added as "Under configuration". Complete the configuration and mappings, then activate it.');
    }

    public function edit(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->assertWritable($p);
        return $this->form($p);
    }

    public function update(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->update($p, $this->protect($request->all(), $p), $this->user());
        $logo = $request->file('logo');
        if ($logo !== null && ($logo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $this->platforms->setLogo($this->find($id), $logo, $this->user());
        }
        return $this->redirectRoute('ecommerce.platforms.show', ['id' => $p['id']])->with('success', 'Platform saved.');
    }

    public function show(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $pid = (int) $p['id'];
        $me = $this->user();
        $db = App::db();
        $tabs = self::TABS;
        if (!can('platforms.view_history')) {
            unset($tabs['history']);
        }
        $tab = $request->string('tab', 'overview');
        $tab = isset($tabs[$tab]) ? $tab : 'overview';
        $fields = App::fields()->accessMap($me, 'platforms');
        $docs = new PlatformDocumentService($db);
        $notes = new PlatformNoteService($db);
        $data = ['title' => $p['name'], 'breadcrumbs' => self::CRUMB + ['Platforms' => route('ecommerce.platforms.index'), $p['platform_code'] => null], 'p' => $p, 'tab' => $tab, 'tabs' => $tabs,
            'fields' => $fields, 'readiness' => $this->platforms->readiness($p), 'writable' => $p['status'] !== 'archived',
            'counts' => ['gst' => count((new DatedMappingService($db, 'platform_gst'))->activeTargetIds($pid)), 'warehouses' => count((new DatedMappingService($db, 'platform_warehouse'))->activeTargetIds($pid)),
                'business' => count((new DatedMappingService($db, 'platform_activity'))->activeTargetIds($pid)) + count((new DatedMappingService($db, 'platform_partner'))->activeTargetIds($pid)),
                'documents' => (int) $db->scalar("SELECT COUNT(*) FROM ecommerce_platform_documents WHERE platform_id = ? AND status = 'active'", [$pid]),
                'notes' => count($notes->visible($pid, $me))]];
        $archived = $request->string('archived') === '1';
        $data += match ($tab) {
            'overview' => ['capabilities' => $this->platforms->capabilities($p), 'archiveCheck' => $p['status'] !== 'archived' ? $this->platforms->archiveCheck($p) : null,
                'recent' => can('platforms.view_history') ? PlatformHistory::read($db, 'h.platform_id = ?', [$pid], 6) : []],
            'configuration' => ['capabilities' => $this->platforms->capabilities($p), 'settings' => $this->platforms->settings($p), 'zones' => \DateTimeZone::listIdentifiers()],
            'gst' => ['mappings' => (new DatedMappingService($db, 'platform_gst'))->list($pid), 'gstOptions' => $this->gstOptions()],
            'business' => ['activities' => (new DatedMappingService($db, 'platform_activity'))->list($pid), 'activityOptions' => $db->select('SELECT id, code, name FROM gst_business_activities WHERE is_active = 1 ORDER BY sort_order, name'),
                'partners' => $this->seePartners() ? (new DatedMappingService($db, 'platform_partner'))->list($pid) : null, 'partnerOptions' => $this->seePartners() ? $this->partnerOptions() : []],
            'warehouses' => ['mappings' => (new DatedMappingService($db, 'platform_warehouse'))->list($pid), 'warehouseOptions' => $this->warehouseOptions()],
            'accounts' => [],
            'documents' => ['documents' => can('platforms.download_documents') || can('platforms.manage_documents') ? $docs->list($pid, $archived) : [], 'types' => PlatformDocumentService::TYPES, 'showArchived' => $archived],
            'notes' => ['notes' => $notes->visible($pid, $me, $archived), 'noteService' => $notes, 'visibility' => PlatformNoteService::VISIBILITY, 'showArchived' => $archived,
                'editNote' => ($e = (int) $request->input('edit', 0)) > 0 ? $notes->find($e) : null],
            'history' => ['history' => PlatformHistory::read($db, 'h.platform_id = ?', [$pid], 300),
                'audit' => $db->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.subject_type = 'platform' AND l.subject_id = ? ORDER BY l.id DESC LIMIT 100", [$pid])],
        };
        if (isset($data['editNote']) && ($data['editNote'] === null || (int) $data['editNote']['platform_id'] !== $pid || !$notes->canEdit($data['editNote'], $me))) {
            $data['editNote'] = null;
        }
        return $this->view('ecommerce/platforms/show', $data);
    }

    public function status(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $to = $request->string('status');
        $this->authorize($to === 'active' ? 'platforms.activate' : 'platforms.deactivate');
        $this->platforms->changeStatus($p, $to, $request->string('reason'), $this->user());
        return $this->toTab($p, 'overview', match ($to) { 'active' => 'Platform activated.', 'inactive' => 'Platform deactivated. Nothing was deleted.', default => 'Status changed.' });
    }

    public function archive(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->archive($p, $request->string('reason'), $request->string('confirm') === '1', $this->user());
        return $this->toTab($p, 'overview', 'Platform archived. It is read-only and its history stays available.');
    }

    public function restore(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->restore($p, $this->user());
        return $this->toTab($p, 'overview', 'Platform restored as inactive.');
    }

    public function logo(Request $request, string $id): Response
    {
        return $this->platforms->logoResponse($this->find($id));
    }

    public function configure(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->configure($p, $request->all(), $this->user());
        return $this->toTab($p, 'configuration', 'Configuration saved.');
    }

    public function capabilities(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->saveCapabilities($p, (array) $request->input('capabilities', []), $this->user());
        return $this->toTab($p, 'configuration', 'Capabilities saved.');
    }

    public function setting(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->saveSetting($p, $request->all(), $this->user());
        return $this->toTab($p, 'configuration', 'Setting saved.');
    }

    public function businessCurrency(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $this->platforms->setBusinessCurrency($p, $request->string('business_currency'), $this->user());
        return $this->toTab($p, 'business', 'Default business currency saved.');
    }

    public function addMapping(Request $request, string $id, string $kind): Response
    {
        [$svcKind, $perm, $tab, $input] = self::MAPPINGS[$kind] ?? throw new HttpException(404, 'Not found.');
        $this->authorize($perm);
        $p = $this->find($id);
        $this->platforms->assertWritable($p);
        $target = (int) $request->input($input, 0);
        $allowed = match ($kind) {
            'gst' => array_column($this->gstOptions(), 'id'),
            'partners' => $this->seePartners(true) ? array_column($this->partnerOptions(), 'id') : [],
            'warehouses' => array_column($this->warehouseOptions(), 'id'),
            default => array_column(App::db()->select('SELECT id FROM gst_business_activities WHERE is_active = 1'), 'id'),
        };
        if (!in_array($target, array_map('intval', $allowed), true)) {
            throw ValidationException::withMessage($input, 'Choose one of the listed options.');
        }
        (new DatedMappingService(App::db(), $svcKind))->add($p, $target, $request->all(), $this->user());
        return $this->toTab($p, $tab, 'Mapping added.');
    }

    public function endMapping(Request $request, string $id, string $kind, string $mapping): Response
    {
        [$svcKind, $perm, $tab] = self::MAPPINGS[$kind] ?? throw new HttpException(404, 'Not found.');
        $this->authorize($perm);
        if ($kind === 'partners' && !$this->seePartners(true)) {
            throw new HttpException(403, 'You cannot change partner mappings.');
        }
        $p = $this->find($id);
        $this->platforms->assertWritable($p);
        (new DatedMappingService(App::db(), $svcKind))->end($p, $this->intParam($mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->toTab($p, $tab, 'Mapping ended. It stays in the history.');
    }

    public function uploadDocument(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $file = $request->file('file');
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw ValidationException::withMessage('file', 'Choose a file to upload.');
        }
        (new PlatformDocumentService(App::db()))->upload($p, $file, $request->all(), $this->user());
        return $this->toTab($p, 'documents', 'Document uploaded.');
    }

    public function document(Request $request, string $id, string $doc): Response
    {
        [$p, $d, $svc] = $this->scopedDocument($id, $doc);
        return $svc->response($d, $request->string('inline') === '1', $this->user());
    }

    public function archiveDocument(Request $request, string $id, string $doc): Response
    {
        [$p, $d, $svc] = $this->scopedDocument($id, $doc);
        $svc->archive($d, $request->string('reason'), $this->user());
        return $this->toTab($p, 'documents', 'Document archived.');
    }

    public function saveNote(Request $request, string $id): Response
    {
        $p = $this->find($id);
        $svc = new PlatformNoteService(App::db());
        $note = null;
        if (($nid = (int) $request->input('note_id', 0)) > 0) {
            $note = $svc->find($nid);
            if ($note === null || (int) $note['platform_id'] !== (int) $p['id'] || !$svc->canSee($note, $this->user())) {
                throw new HttpException(404, 'Note not found.');
            }
        }
        $svc->save($p, $request->all(), $note, $this->user());
        return $this->toTab($p, 'notes', $note === null ? 'Note added.' : 'Note saved.');
    }

    public function archiveNote(Request $request, string $id, string $note): Response
    {
        $p = $this->find($id);
        $svc = new PlatformNoteService(App::db());
        $n = $svc->find($this->intParam($note));
        if ($n === null || (int) $n['platform_id'] !== (int) $p['id'] || !$svc->canSee($n, $this->user())) {
            throw new HttpException(404, 'Note not found.');
        }
        $svc->archive($p, $n, $this->user());
        return $this->toTab($p, 'notes', 'Note archived.');
    }

    /** History of all visible platforms. */
    public function history(Request $request): Response
    {
        $me = $this->user();
        [$w, $params] = PlatformScope::sql($me, 'h.platform_id');
        $f = $this->filters($request);
        if (($f['platform'] ?? '') !== '') {
            $w .= ' AND h.platform_id = ?';
            $params[] = (int) $f['platform'];
        }
        if (($ev = $request->string('event')) !== '' && preg_match('/^[a-z_]{2,40}$/', $ev) === 1) {
            $w .= ' AND h.event = ?';
            $params[] = $ev;
        }
        if ($f['from'] !== '') {
            $w .= ' AND h.created_at >= ?';
            $params[] = $f['from'] . ' 00:00:00';
        }
        if ($f['to'] !== '') {
            $w .= ' AND h.created_at <= ?';
            $params[] = $f['to'] . ' 23:59:59';
        }
        return $this->view('ecommerce/history', ['title' => 'Platform history', 'breadcrumbs' => self::CRUMB + ['History' => null], 'f' => $f, 'event' => $ev,
            'history' => PlatformHistory::read(App::db(), $w, $params, 500), 'options' => $this->platforms->options($me, true),
            'events' => array_column(App::db()->select('SELECT DISTINCT event FROM ecommerce_platform_history ORDER BY event'), 'event')]);
    }

    public function categories(Request $request): Response
    {
        return $this->view('ecommerce/categories', ['title' => 'Platform categories', 'breadcrumbs' => self::CRUMB + ['Categories' => null],
            'categories' => (new PlatformCategoryService(App::db()))->all(), 'edit' => (int) $request->input('edit', 0),
            'capabilities' => PlatformService::CAPABILITIES]);
    }

    public function saveCategory(Request $request): Response
    {
        $id = (int) $request->input('category_id', 0);
        (new PlatformCategoryService(App::db()))->save($request->all(), $id > 0 ? $id : null, $this->user());
        return $this->redirectRoute('ecommerce.categories')->with('success', 'Category saved.');
    }

    // ---------------------------------------------------------------- internals

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $pid = $this->intParam($id);
        PlatformScope::assert($this->user(), $pid);
        return $this->platforms->find($pid) ?? throw new HttpException(404, 'Platform not found.');
    }

    private function form(?array $p): Response
    {
        return $this->view('ecommerce/platforms/form', [
            'title' => $p === null ? 'Add platform' : 'Edit ' . $p['name'],
            'breadcrumbs' => self::CRUMB + ['Platforms' => route('ecommerce.platforms.index')] + ($p !== null ? [$p['platform_code'] => route('ecommerce.platforms.show', ['id' => $p['id']])] : []) + [$p === null ? 'Add' : 'Edit' => null],
            'p' => $p, 'categories' => (new PlatformCategoryService(App::db()))->all(true), 'fields' => App::fields()->accessMap($this->user(), 'platforms'),
        ]);
    }

    /** Users who may not edit a protected field keep the stored value. @param array<string, mixed> $input @param array<string, mixed>|null $p @return array<string, mixed> */
    private function protect(array $input, ?array $p): array
    {
        foreach (['internal_notes', 'website_url'] as $field) {
            if (!App::fields()->access($this->user(), 'platforms', $field)->canEdit()) {
                $input[$field] = $p[$field] ?? '';
            }
        }
        return $input;
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $f = [];
        foreach (['q', 'status', 'connection', 'country', 'from', 'to', 'archived'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && !\App\Core\Validator::isDate($f[$k])) {
                $f[$k] = '';
            }
        }
        foreach (['category', 'platform', 'gst'] as $k) {
            $f[$k] = max(0, (int) $request->input($k, 0)) ?: '';
        }
        return $f;
    }

    /** @return list<array<string, mixed>> */
    private function gstOptions(): array
    {
        if (!can('gst_registrations.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'gst_registrations', 'g');
        return App::db()->select("SELECT g.id, g.gstin, g.legal_name, g.trade_name FROM gst_registrations g WHERE {$w} AND g.is_archived = 0 AND g.status <> 'cancelled' ORDER BY g.gstin", $p);
    }

    /** @return list<array<string, mixed>> */
    private function warehouseOptions(): array
    {
        if (!can('inventory.view')) {
            return [];
        }
        return array_values(array_filter((new WarehouseService(App::db()))->options($this->user(), true), static fn (array $w): bool => ($w['status'] ?? '') !== 'archived'));
    }

    /** @return list<array<string, mixed>> */
    private function partnerOptions(): array
    {
        if (!can('partners.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'partners', 'pa');
        return App::db()->select("SELECT pa.id, pa.partner_code, pa.name FROM partners pa WHERE {$w} AND pa.status <> 'archived' ORDER BY pa.name", $p);
    }

    private function seePartners(bool $edit = false): bool
    {
        $a = App::fields()->access($this->user(), 'platforms', 'partner_mappings');
        return $edit ? $a->canEdit() : $a->canView();
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: PlatformDocumentService} */
    private function scopedDocument(string $id, string $doc): array
    {
        $p = $this->find($id);
        $svc = new PlatformDocumentService(App::db());
        $d = $svc->find($this->intParam($doc));
        if ($d === null || (int) $d['platform_id'] !== (int) $p['id']) {
            throw new HttpException(404, 'Document not found.');
        }
        return [$p, $d, $svc];
    }

    /** @param array<string, mixed> $p */
    private function toTab(array $p, string $tab, string $message): Response
    {
        return $this->redirectRoute('ecommerce.platforms.show', ['id' => $p['id'], 'tab' => $tab])->with('success', $message);
    }
}
