<?php

declare(strict_types=1);

namespace App\Controllers\Gst;

use App\Core\App;
use App\Core\Controller;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Export\TableExport;
use App\Services\Gst\GstDocumentService;
use App\Services\Gst\GstinValidator;
use App\Services\Gst\GstLinkRegistry;
use App\Services\Gst\GstMasterService;
use App\Services\Gst\GstRegistrationService;
use App\Services\Gst\GstRelationService;
use App\Services\Gst\GstReportService;
use App\Services\Partners\PartnerService;

/**
 * GST Management dashboard, register, add/edit forms, profile tabs and status
 * actions. Record scope (gst_registrations) is applied in SQL, field rules
 * before rendering and on writes; every action has its own permission.
 */
final class GstRegistrationController extends Controller
{
    public const TABS = [
        'overview' => ['Overview', null], 'details' => ['Registration details', null], 'partners' => ['Partner associations', null],
        'activities' => ['Business activities', null], 'locations' => ['Business locations', null], 'documents' => ['Documents', 'gst_registrations.download_documents'],
        'settings' => ['Settings', null], 'history' => ['History & audit', 'gst_registrations.view_history'],
    ];
    private const PROTECTED_FIELDS = ['gstin', 'legal_name', 'trade_name', 'pan', 'contact_person', 'contact_phone', 'contact_email', 'portal_reference', 'internal_notes'];

    private GstRegistrationService $gst;

    public function __construct()
    {
        $this->gst = new GstRegistrationService(App::db());
    }

    public function dashboard(Request $request): Response
    {
        $me = $this->user();
        $scope = App::dataAccess()->constrain($me, 'gst_registrations', 'g');
        $masters = new GstMasterService(App::db());
        return $this->view('gst/dashboard', [
            'title' => 'GST Management',
            'breadcrumbs' => ['GST Management' => null],
            'd' => $this->gst->dashboard($scope),
            'scopeLabel' => App::dataAccess()->resolve($me, 'gst_registrations')->describe(),
            'states' => $masters->states(),
            'types' => $masters->types(),
            'partners' => $this->partnerOptions($me),
            'gstFields' => App::fields()->accessMap($me, 'gst_registrations'),
            'default' => (new GstRelationService(App::db()))->defaultRegistration(),
        ]);
    }

    public function index(Request $request): Response
    {
        $me = $this->user();
        $filters = $this->filters($request);
        $scope = App::dataAccess()->constrain($me, 'gst_registrations', 'g');
        $page = $this->gst->paginate($filters, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15), $scope);
        $masters = new GstMasterService(App::db());
        return $this->view('gst/index', [
            'title' => 'GST registrations',
            'breadcrumbs' => ['GST Management' => route('gst.dashboard'), 'Registrations' => null],
            'rows' => new Paginator(App::fields()->presentMany($me, 'gst_registrations', $page->items), $page->total, $page->page, $page->perPage),
            'filters' => $filters,
            'states' => $masters->states(),
            'types' => $masters->types(),
            'partners' => $this->partnerOptions($me),
            'scopeLabel' => App::dataAccess()->resolve($me, 'gst_registrations')->describe(),
            'fieldAccess' => App::fields()->accessMap($me, 'gst_registrations'),
        ]);
    }

    /** Register export (CSV / Excel / PDF) — same scope, filters and field rules as the page. */
    public function export(Request $request): Response
    {
        $me = $this->user();
        $format = $request->string('format', 'csv');
        $result = (new GstReportService(App::db()))->run($me, 'all', $this->filters($request));
        App::activity()->log('gst.exported', 'Exported ' . count($result['rows']) . " GST registration(s) as {$format}", module: 'gst_registrations',
            newValues: ['rows' => count($result['rows']), 'format' => $format, 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'gst-registrations');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, self::rules(true), self::labels());
        [$id, $change] = $this->gst->create($data, $this->user());
        $msg = $change['status'] === 'approved'
            ? 'GST registration added.' . (($change['new_values']['status'] ?? '') === 'pending_verification' ? ' It is pending verification: check it against the certificate, upload the certificate and activate it.' : '')
            : 'GST registration added and sent for approval. It becomes usable once approved.';
        return $this->redirectRoute('gst.show', ['id' => $id])->with('success', $msg);
    }

    /** Live GSTIN check for the form (same server-side rules as saving). */
    public function check(Request $request): Response
    {
        if (!can('gst_registrations.create') && !can('gst_registrations.edit')) {
            throw new HttpException(403, 'Not allowed.');
        }
        $type = (int) $request->input('registration_type_id', 0) > 0 ? App::db()->first('SELECT * FROM gst_registration_types WHERE id = ?', [(int) $request->input('registration_type_id')]) : null;
        $r = (new GstinValidator(App::db()))->validate($request->string('gstin'), $type, $request->string('pan') ?: null);
        $except = (int) $request->input('except', 0);
        if ($r['valid'] && App::db()->scalar('SELECT 1 FROM gst_registrations WHERE gstin = ?' . ($except > 0 ? ' AND id <> ?' : ''), $except > 0 ? [$r['gstin'], $except] : [$r['gstin']])) {
            $r['valid'] = false;
            $r['errors'][] = 'This GSTIN is already registered.';
        }
        return Response::json([
            'valid' => $r['valid'], 'gstin' => $r['gstin'], 'errors' => $r['errors'],
            'state' => $r['state'] !== null ? ['id' => (int) $r['state']['id'], 'code' => $r['state']['code'], 'name' => $r['state']['name']] : null,
            'pan' => $r['pan'],
            'notice' => 'Format check only — this does not confirm the registration on the GST portal.',
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $reg = $this->findReg($id);
        $rid = (int) $reg['id'];
        $tabs = array_filter(self::TABS, static fn (array $t): bool => $t[1] === null || can($t[1]));
        $tab = $request->string('tab', 'overview');
        if (!isset($tabs[$tab])) {
            $tab = 'overview';
        }
        $rel = new GstRelationService(App::db());
        $masters = new GstMasterService(App::db());
        $fields = App::fields()->accessMap($me, 'gst_registrations');
        $addressAccess = App::fields()->access($me, 'gst_registrations', 'address');
        $data = [
            'title' => $reg['legal_name'],
            'breadcrumbs' => ['GST Management' => route('gst.dashboard'), 'Registrations' => route('gst.index'), $reg['gstin'] => null],
            'reg' => App::fields()->present($me, 'gst_registrations', $reg),
            'raw' => ['id' => $rid, 'status' => $reg['status'], 'is_archived' => (int) $reg['is_archived'], 'approval_state' => $reg['approval_state']],
            'tab' => $tab,
            'tabs' => array_map(static fn (array $t): string => $t[0], $tabs),
            'fieldAccess' => $fields,
            'addressAccess' => $addressAccess,
            'pending' => $this->gst->pendingChange($rid),
            'principal' => self::presentAddress($rel->principal($rid), $addressAccess),
            'transitions' => array_filter(GstRegistrationService::TRANSITIONS, static fn (array $t, string $to): bool => in_array($reg['status'], $t[1], true) && can('gst_registrations.' . $t[0]), ARRAY_FILTER_USE_BOTH),
            'partnerCount' => (int) App::db()->scalar("SELECT COUNT(*) FROM gst_registration_partner_mappings WHERE gst_registration_id = ? AND status = 'active'", [$rid]),
            'activityCount' => (int) App::db()->scalar("SELECT COUNT(*) FROM gst_registration_business_mappings WHERE gst_registration_id = ? AND status = 'active'", [$rid]),
            'locationCount' => (int) App::db()->scalar("SELECT COUNT(*) FROM gst_registration_addresses WHERE gst_registration_id = ? AND status = 'active'", [$rid]),
            'documentCount' => (int) App::db()->scalar("SELECT COUNT(*) FROM gst_registration_documents WHERE gst_registration_id = ? AND status = 'active'", [$rid]),
            'settings' => $rel->settings($rid),
        ];
        $data += match ($tab) {
            'overview' => ['links' => (new GstLinkRegistry(App::db()))->linksFor($rid), 'linkTypes' => GstLinkRegistry::types(), 'statusHistory' => array_slice($this->gst->statusHistory($rid), 0, 5)],
            'partners' => ['mappings' => $this->presentMappings($me, $rel->partnerMappings($rid)), 'partnerOptions' => can('gst_registrations.manage_partners') ? $this->partnerOptions($me, true) : [],
                'relationships' => $masters->relationships(true), 'singleOwner' => (bool) App::settings()->get('gst_single_owner_rule', false)],
            'activities' => ['mappings' => $rel->activityMappings($rid), 'activities' => $masters->activities(true)],
            'locations' => ['locations' => array_map(static fn (array $l): array => self::presentAddress($l, $addressAccess), $rel->locations($rid)), 'states' => $masters->states(true)],
            'documents' => ['documents' => (new GstDocumentService(App::db()))->forRegistration($rid, $request->string('archived') === '1'), 'docTypes' => GstDocumentService::TYPES, 'showArchived' => $request->string('archived') === '1'],
            'settings' => ['states' => $masters->states(true), 'types' => $masters->types(true), 'defaultReg' => $rel->defaultRegistration()],
            'history' => ['statusHistory' => $this->gst->statusHistory($rid), 'changes' => $this->gst->changeRequests($rid), 'audit' => $this->audit($rid)],
            default => [],
        };
        return $this->view('gst/show', $data);
    }

    public function edit(Request $request, string $id): Response
    {
        $reg = $this->findReg($id);
        $this->gst->assertWritable($reg);
        return $this->form($reg);
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->user();
        $reg = $this->findReg($id);
        // Fields this user may not edit are rejected, even in crafted requests.
        App::fields()->assertWritable($me, 'gst_registrations', array_intersect_key($request->all(), array_flip(self::PROTECTED_FIELDS)), $reg);
        $rules = self::rules(false);
        $access = App::fields()->accessMap($me, 'gst_registrations');
        foreach (self::PROTECTED_FIELDS as $f) {
            if (isset($access[$f]) && !$access[$f]->canEdit()) {
                unset($rules[$f]);
            }
        }
        if (isset($access['address']) && !$access['address']->canEdit()) {
            foreach (array_keys($rules) as $k) {
                if (str_starts_with($k, 'addr_')) {
                    unset($rules[$k]);
                }
            }
        }
        $data = $this->validate($request, $rules, self::labels());
        $result = $this->gst->update($reg, $data, $me, $request->string('change_reason'));
        if ($result['direct'] === [] && $result['key'] === []) {
            return $this->redirectRoute('gst.show', ['id' => $reg['id'], 'tab' => 'details'])->with('info', 'No changes to save.');
        }
        $parts = [];
        if ($result['direct'] !== []) {
            $parts[] = 'Saved.';
        }
        if ($result['change'] !== null) {
            $parts[] = $result['change']['status'] === 'approved'
                ? 'Key details changed (' . implode(', ', $result['key']) . ').'
                : 'The key-detail change (' . implode(', ', $result['key']) . ') was sent for approval and applies once approved.';
        }
        return $this->redirectRoute('gst.show', ['id' => $reg['id'], 'tab' => 'details'])->with($result['change'] !== null && $result['change']['status'] === 'pending' ? 'info' : 'success', implode(' ', $parts));
    }

    public function status(Request $request, string $id): Response
    {
        $reg = $this->findReg($id);
        $data = $this->validate($request, ['status' => 'required|in:active,inactive,suspended,cancelled', 'reason' => 'nullable|string|max:255', 'effective_date' => 'nullable|date'],
            ['effective_date' => 'Date']);
        $change = $this->gst->changeStatus($reg, (string) $data['status'], $data['reason'] ?? null, $data['effective_date'] ?? null, $this->user());
        if ($change !== null) {
            return $this->back($reg, $change['status'] === 'approved' ? 'Registration marked as cancelled.' : 'Cancellation sent for approval. The registration stays as it is until approved.', $change['status'] === 'approved' ? 'success' : 'info');
        }
        return $this->back($reg, 'Status changed to ' . strtolower(GstRegistrationService::STATUSES[$data['status']]) . '.');
    }

    public function archive(Request $request, string $id): Response
    {
        $reg = $this->findReg($id);
        $data = $this->validate($request, ['reason' => 'nullable|string|max:255']);
        $this->gst->archive($reg, (string) ($data['reason'] ?? ''), $this->user());
        return $this->back($reg, 'Registration archived. It is read-only and hidden from the register unless you show archived records.');
    }

    public function restore(Request $request, string $id): Response
    {
        $reg = $this->findReg($id);
        $this->gst->restore($reg, $this->user());
        return $this->back($reg, 'Registration restored.');
    }

    public function resubmit(Request $request, string $id): Response
    {
        $reg = $this->findReg($id);
        $change = $this->gst->resubmit($reg, $this->user());
        return $this->back($reg, $change['status'] === 'approved' ? 'Registration approved.' : 'Registration re-submitted for approval.', 'info');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    public function findReg(string $id): array
    {
        $rid = $this->intParam($id);
        App::dataAccess()->assertCanAccess($this->user(), 'gst_registrations', $rid);
        return $this->gst->find($rid) ?? throw new HttpException(404, 'GST registration not found.');
    }

    private function form(?array $reg): Response
    {
        $me = $this->user();
        $masters = new GstMasterService(App::db());
        $rid = $reg !== null ? (int) $reg['id'] : null;
        $principal = $rid !== null ? (new GstRelationService(App::db()))->principal($rid) : null;
        return $this->view('gst/form', [
            'title' => $reg === null ? 'Add GST registration' : 'Edit ' . $reg['gstin'],
            'breadcrumbs' => ['GST Management' => route('gst.dashboard'), 'Registrations' => route('gst.index')] + ($reg === null ? ['Add' => null] : [$reg['gstin'] => route('gst.show', ['id' => $rid]), 'Edit' => null]),
            'reg' => $reg !== null ? App::fields()->present($me, 'gst_registrations', $reg) : null,
            'principal' => $principal,
            'states' => $masters->states(true),
            'types' => $masters->types(true),
            'fieldAccess' => $reg !== null ? App::fields()->accessMap($me, 'gst_registrations') : [],
            'addressAccess' => $reg !== null ? App::fields()->access($me, 'gst_registrations', 'address') : null,
            'pending' => $rid !== null ? $this->gst->pendingChange($rid) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $f = [];
        foreach (['q', 'gstin', 'legal_name', 'trade_name', 'status', 'archived', 'from', 'to', 'sort'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        foreach (['partner', 'state', 'type'] as $k) {
            $f[$k] = max(0, (int) $request->input($k, 0)) ?: '';
        }
        return $f;
    }

    /** Partners the user may see (for filters and mapping). @param array<string, mixed> $me @return list<array<string, mixed>> */
    private function partnerOptions(array $me, bool $excludeArchived = false): array
    {
        if (!can('partners.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($me, 'partners', 'p');
        return App::db()->select("SELECT p.id, p.partner_code, p.name, p.status FROM partners p WHERE {$w}" . ($excludeArchived ? " AND p.status <> 'archived'" : '') . ' ORDER BY p.name', $p);
    }

    /**
     * Partner names are shown only when the viewer may see that partner; otherwise just the partner ID.
     * @param array<string, mixed> $me @param list<array<string, mixed>> $rows @return list<array<string, mixed>>
     */
    private function presentMappings(array $me, array $rows): array
    {
        $visible = array_flip(array_map(static fn (array $p): int => (int) $p['id'], $this->partnerOptions($me)));
        return array_map(static function (array $m) use ($visible): array {
            $m['partner_visible'] = isset($visible[(int) $m['partner_id']]);
            if (!$m['partner_visible']) {
                $m['partner_name'] = null;
            }
            return $m;
        }, $rows);
    }

    /** Apply the "Business addresses" field rule to an address row. @param array<string, mixed>|null $a */
    public static function presentAddress(?array $a, mixed $access): ?array
    {
        if ($a === null || $access === null) {
            return $a;
        }
        if (!$access->canView()) {
            return ['hidden' => true] + array_intersect_key($a, array_flip(['id', 'is_principal', 'status', 'label', 'state_code', 'state_name']));
        }
        if (!$access->showsRealValue()) {
            foreach (['address_line1', 'address_line2', 'district', 'pin_code', 'contact_name', 'contact_phone', 'contact_email'] as $k) {
                if (($a[$k] ?? '') !== '' && $a[$k] !== null) {
                    $a[$k] = \App\Services\Authorization\FieldPolicy::mask((string) $a[$k], 'full');
                }
            }
            $a['masked'] = true;
        }
        return $a;
    }

    /** Audit trail of this registration (registration, mappings, locations, documents, settings, approvals). @return list<array<string, mixed>> */
    private function audit(int $rid): array
    {
        return App::db()->select(
            "SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
             WHERE (l.subject_type = 'gst_registration' AND l.subject_id = ?)
                OR (l.subject_type = 'approval_request' AND l.subject_id IN (SELECT approval_request_id FROM gst_registration_change_requests WHERE gst_registration_id = ? AND approval_request_id IS NOT NULL))
             ORDER BY l.id DESC LIMIT 200",
            [$rid, $rid]
        );
    }

    /** @param array<string, mixed> $reg */
    private function back(array $reg, string $message, string $type = 'success'): Response
    {
        return $this->redirectRoute('gst.show', ['id' => $reg['id']])->with($type, $message);
    }

    /** @return array<string, string> */
    public static function rules(bool $creating): array
    {
        $statuses = implode(',', GstRegistrationService::INITIAL_STATUSES);
        return ($creating ? ['status' => "required|in:{$statuses}"] : []) + [
            'gstin' => 'required|string|max:30',
            'legal_name' => 'required|string|min:2|max:200',
            'trade_name' => 'nullable|string|max:200',
            'pan' => 'nullable|string|max:12',
            'registration_type_id' => 'required|integer',
            'registration_date' => 'nullable|date|before_or_equal:today',
            'effective_from' => 'nullable|date',
            'validity_end_date' => 'nullable|date',
            'business_constitution' => 'nullable|in:' . implode(',', array_map(static fn (string $c): string => str_replace(',', '', $c), GstRegistrationService::CONSTITUTIONS)),
            'taxpayer_category' => 'nullable|in:' . implode(',', GstRegistrationService::TAXPAYER_CATEGORIES),
            'nature_of_business' => 'nullable|string|max:500',
            'contact_person' => 'nullable|string|max:150',
            'contact_phone' => 'nullable|string|regex:/^(\+?\d{1,3}[\s-]?)?[6-9]\d{9}$|^\+?[0-9][0-9\s-]{6,18}$/',
            'contact_email' => 'nullable|email|max:191',
            'portal_reference' => 'nullable|string|max:120',
            'internal_notes' => 'nullable|string|max:5000',
            'addr_line1' => 'required|string|max:200',
            'addr_line2' => 'nullable|string|max:200',
            'addr_city' => 'required|string|max:100',
            'addr_district' => 'nullable|string|max:100',
            'addr_state_id' => 'required|integer',
            'addr_pin_code' => 'required|string|regex:/^[1-9][0-9]{5}$/',
            'addr_country' => 'nullable|string|max:80',
            'addr_contact_name' => 'nullable|string|max:150',
            'addr_contact_phone' => 'nullable|string|regex:/^(\+?\d{1,3}[\s-]?)?[6-9]\d{9}$|^\+?[0-9][0-9\s-]{6,18}$/',
            'addr_contact_email' => 'nullable|email|max:191',
        ];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'gstin' => 'GSTIN', 'pan' => 'PAN', 'legal_name' => 'Legal business name', 'registration_type_id' => 'Registration type', 'registration_date' => 'Registration date',
            'effective_from' => 'Effective from', 'validity_end_date' => 'Validity end date', 'contact_phone' => 'Contact number', 'contact_email' => 'Email address',
            'addr_line1' => 'Address line 1', 'addr_line2' => 'Address line 2', 'addr_city' => 'City', 'addr_district' => 'District', 'addr_state_id' => 'State',
            'addr_pin_code' => 'PIN code', 'addr_country' => 'Country', 'addr_contact_phone' => 'Location contact number', 'addr_contact_email' => 'Location email',
            'portal_reference' => 'GST portal reference', 'business_constitution' => 'Business constitution', 'taxpayer_category' => 'Taxpayer category',
        ];
    }
}
