<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

use App\Core\App;
use App\Core\Controller;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Export\TableExport;
use App\Services\Partners\CapitalService;
use App\Services\Partners\DocumentService;
use App\Services\Partners\PartnerReportService;
use App\Services\Partners\PartnerService;
use App\Services\Partners\ProfitSharingService;

/**
 * Partner directory and profile. Record scope (partners module) is applied in
 * SQL; field rules are applied before rendering; every tab checks the
 * permission of the data it shows.
 */
final class PartnerController extends Controller
{
    public const TABS = [
        'overview' => 'Overview', 'personal' => 'Personal information', 'contributions' => 'Capital contributions', 'withdrawals' => 'Capital withdrawals',
        'balance' => 'Capital balance', 'profit' => 'Profit sharing', 'gst' => 'GST registrations', 'bank' => 'Bank accounts',
        'reports' => 'Financial reports', 'documents' => 'Documents', 'activity' => 'Activity history',
    ];

    private PartnerService $partners;

    public function __construct()
    {
        $this->partners = new PartnerService(App::db());
    }

    public function index(Request $request): Response
    {
        $me = $this->user();
        $filters = ['q' => mb_substr($request->string('q'), 0, 100), 'status' => $request->string('status')];
        $scope = App::dataAccess()->constrain($me, 'partners', 'p');
        $page = $this->partners->paginate($filters, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15), $scope);
        $rows = App::fields()->presentMany($me, 'partners', $page->items);
        $balances = [];
        if (can('partners.view_financial_summary') && $rows !== []) {
            $ids = array_map('intval', array_column($rows, 'id'));
            foreach (App::capitalLedger()->summaries(['p.id IN (' . implode(',', $ids) . ')', []]) as $id => $s) {
                $balances[$id] = App::fields()->present($me, 'partners', ['capital_balance' => $s['closing']]);
            }
        }
        return $this->view('partners/index', [
            'title' => 'Partners',
            'breadcrumbs' => ['Partners' => null],
            'partners' => new Paginator($rows, $page->total, $page->page, $page->perPage),
            'balances' => $balances,
            'filters' => $filters,
            'counts' => $this->partners->counts($scope),
            'scopeLabel' => App::dataAccess()->resolve($me, 'partners')->describe(),
            'fieldAccess' => App::fields()->accessMap($me, 'partners'),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('partners/form', [
            'title' => 'Add partner',
            'breadcrumbs' => ['Partners' => route('partners.index'), 'Add partner' => null],
            'partner' => null,
            'fieldAccess' => [],
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, self::rules(null), self::labels());
        $id = $this->partners->create($data, $this->user());
        $photo = $request->file('photo');
        if ($photo !== null && can('partners.upload_documents')) {
            (new DocumentService(App::db()))->store($id, $photo, 'photo', 'Profile photo', $this->user());
        }
        return $this->redirectRoute('partners.show', ['id' => $id])->with('success', 'Partner added. A capital account was opened with a zero balance.');
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $partner = $this->findPartner($id);
        $pid = (int) $partner['id'];
        $tab = $request->string('tab', 'overview');
        if (!isset(self::TABS[$tab])) {
            $tab = 'overview';
        }
        $visibleTabs = array_filter(self::TABS, fn (string $label, string $key): bool => $this->tabAllowed($me, $key, $pid), ARRAY_FILTER_USE_BOTH);
        if (!isset($visibleTabs[$tab])) {
            $tab = 'overview';
        }
        $data = [
            'title' => $partner['name'],
            'breadcrumbs' => ['Partners' => route('partners.index'), $partner['name'] => null],
            'partner' => App::fields()->present($me, 'partners', $partner),
            'raw' => ['id' => $pid, 'status' => $partner['status'], 'user_id' => $partner['user_id']],
            'tab' => $tab,
            'tabs' => $visibleTabs,
            'fieldAccess' => App::fields()->accessMap($me, 'partners'),
            'summary' => null,
        ];
        if (can('partners.view_financial_summary')) {
            $s = App::capitalLedger()->summary($pid);
            $data['summary'] = $s + ['presented' => App::fields()->present($me, 'partners', ['total_contributed' => $s['net_contributed'] ?? 0, 'total_withdrawn' => $s['net_withdrawn'] ?? 0, 'capital_balance' => $s['closing'] ?? 0])];
            $data['activeShares'] = can('profit_sharing.view') ? (new ProfitSharingService(App::db()))->activeFor($pid) : null;
        }
        $data += match ($tab) {
            'contributions', 'withdrawals' => $this->capitalTab($me, $pid, $tab === 'contributions' ? 'contribution' : 'withdrawal', $request),
            'balance' => ['ledger' => App::capitalLedger()->entries($pid, $request->string('from') ?: null, $request->string('to') ?: null),
                'ledgerFields' => App::fields()->accessMap($me, 'capital_ledger'), 'from' => $request->string('from'), 'to' => $request->string('to')],
            'profit' => ['shares' => (new ProfitSharingService(App::db()))->partnerHistory($pid), 'percentAccess' => App::fields()->access($me, 'profit_sharing', 'percentage')],
            'documents' => ['documents' => (new DocumentService(App::db()))->forPartner($me, $pid), 'categories' => array_filter(DocumentService::CATEGORIES,
                fn (string $label, string $key): bool => $key !== 'photo' && (new DocumentService(App::db()))->categoryVisible($me, $key), ARRAY_FILTER_USE_BOTH)],
            'activity' => ['activity' => $this->activity($pid)],
            'reports' => ['reports' => (new PartnerReportService(App::db()))->available($me)],
            // Phase 4: GST registrations mapped to this partner, within the viewer's GST record scope and field rules.
            'gst' => ['gstMappings' => App::fields()->presentMany($me, 'gst_registrations',
                (new \App\Services\Gst\GstRelationService(App::db()))->registrationsForPartner($pid, App::dataAccess()->constrain($me, 'gst_registrations', 'g')))],
            'personal' => ['links' => can('partners.link_account') ? $this->partners->linkHistory($pid) : [], 'linkable' => can('partners.link_account') && $partner['user_id'] === null ? $this->partners->linkableUsers() : []],
            default => [],
        };
        return $this->view('partners/show', $data);
    }

    public function edit(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $this->partners->assertNotArchived($partner);
        $me = $this->user();
        return $this->view('partners/form', [
            'title' => 'Edit ' . $partner['name'],
            'breadcrumbs' => ['Partners' => route('partners.index'), $partner['name'] => route('partners.show', ['id' => $partner['id']]), 'Edit' => null],
            'partner' => App::fields()->present($me, 'partners', $partner),
            'fieldAccess' => App::fields()->accessMap($me, 'partners'),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $me = $this->user();
        $protected = ['name', 'email', 'mobile', 'address', 'pan_number', 'notes'];
        // Fields the user may not edit are rejected even in crafted requests.
        App::fields()->assertWritable($me, 'partners', array_intersect_key($request->all(), array_flip($protected)), $partner);
        $access = App::fields()->accessMap($me, 'partners');
        $rules = self::rules((int) $partner['id']);
        foreach ($protected as $f) {
            if (isset($access[$f]) && !$access[$f]->canEdit()) {
                unset($rules[$f]);
            }
        }
        $data = $this->validate($request, $rules, self::labels());
        $this->partners->update($partner, $data, $me);
        return $this->redirectRoute('partners.show', ['id' => $partner['id'], 'tab' => 'personal'])->with('success', 'Partner details saved.');
    }

    public function status(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $data = $this->validate($request, ['status' => 'required|in:active,inactive', 'reason' => 'nullable|string|max:255']);
        $this->partners->setStatus($partner, (string) $data['status'], $data['reason'], $this->user());
        return $this->back($partner, $data['status'] === 'active' ? 'Partner activated.' : 'Partner deactivated. No new capital entries can be recorded until it is activated again.');
    }

    public function suspend(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $data = $this->validate($request, ['reason' => 'required|string|max:255']);
        $login = $this->partners->suspend($partner, (string) $data['reason'], $this->user());
        return $this->back($partner, 'Partner suspended.' . ($login ? ' The linked login was suspended and signed out.' : ($partner['user_id'] ? ' The linked login was not changed (you may not suspend logins).' : '')));
    }

    public function reactivate(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $this->partners->reactivate($partner, $this->user());
        return $this->back($partner, 'Partner reactivated.' . ($partner['user_id'] ? ' Reactivate the linked login separately under Personal information if it was suspended.' : ''));
    }

    public function archive(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $data = $this->validate($request, ['reason' => 'nullable|string|max:255']);
        $this->partners->archive($partner, (string) ($data['reason'] ?? ''), $this->user());
        return $this->back($partner, 'Partner archived. History is kept and the profile is now read-only.');
    }

    public function restore(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $this->partners->restore($partner, $this->user());
        return $this->back($partner, 'Partner restored as inactive. Activate it to record new capital entries.');
    }

    /** Partner list export (CSV / Excel / PDF) — same scope and field rules as the page. */
    public function export(Request $request): Response
    {
        $me = $this->user();
        $format = $request->string('format', 'csv');
        // Needs partners.export only (route middleware); record scope and field rules still apply.
        $result = (new PartnerReportService(App::db()))->runUnchecked($me, 'partners', ['q' => $request->string('q'), 'status' => $request->string('status')]);
        App::activity()->log('partners.exported', 'Exported ' . count($result['rows']) . " partner record(s) as {$format}", module: 'partners',
            newValues: ['rows' => count($result['rows']), 'format' => $format, 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'partners');
    }

    /** Profile photo (private storage, authorised). */
    public function photo(Request $request, string $id): Response
    {
        $partner = $this->findPartner($id);
        $doc = $partner['photo_document_id'] ? (new DocumentService(App::db()))->find((int) $partner['photo_document_id']) : null;
        if ($doc === null || $doc['category'] !== 'photo') {
            throw new HttpException(404, 'No photo.');
        }
        return (new DocumentService(App::db()))->response($doc, inline: true);
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function findPartner(string $id): array
    {
        $pid = $this->intParam($id);
        App::dataAccess()->assertCanAccess($this->user(), 'partners', $pid);
        return $this->partners->find($pid) ?? throw new HttpException(404, 'Partner not found.');
    }

    /** @param array<string, mixed> $me */
    private function tabAllowed(array $me, string $tab, int $pid): bool
    {
        $da = App::dataAccess();
        $partnerVisibleIn = static function (string $module) use ($da, $me, $pid): bool {
            [$w, $p] = $da->constrainAs($me, $module, 'partners', 'p');
            return (bool) App::db()->scalar("SELECT 1 FROM partners p WHERE p.id = ? AND {$w}", array_merge([$pid], $p));
        };
        return match ($tab) {
            'overview', 'personal', 'bank' => true,
            'gst' => can('gst_registrations.view'),
            'contributions' => can('capital_contributions.view') && $partnerVisibleIn('capital_contributions'),
            'withdrawals' => can('capital_withdrawals.view') && $partnerVisibleIn('capital_withdrawals'),
            'balance' => can('capital_ledger.view') && $partnerVisibleIn('capital_ledger'),
            'profit' => can('profit_sharing.view'),
            'documents' => can('partners.view_documents'),
            'reports' => can('partner_reports.view'),
            'activity' => can('activity_logs.view'),
            default => false,
        };
    }

    /** @param array<string, mixed> $me */
    private function capitalTab(array $me, int $pid, string $type, Request $request): array
    {
        $module = CapitalService::module($type);
        $svc = new CapitalService(App::db());
        $scope = App::dataAccess()->constrain($me, $module, 't');
        $filters = ['partner' => $pid, 'status' => $request->string('status')];
        $page = $svc->paginate($type, $filters, max(1, (int) $request->input('page', 1)), 10, $scope);
        return [
            'entries' => new Paginator(App::fields()->presentMany($me, $module, $page->items), $page->total, $page->page, $page->perPage),
            'entryTotals' => $svc->totals($type, $filters, $scope),
            'capitalType' => $type,
            'capitalModule' => $module,
            'amountAccess' => App::fields()->access($me, $module, 'amount'),
            'statusFilter' => $filters['status'],
        ];
    }

    /** Audit trail of the partner, its capital entries and documents. @return list<array<string, mixed>> */
    private function activity(int $pid): array
    {
        return App::db()->select(
            "SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
             WHERE (l.subject_type = 'partner' AND l.subject_id = ?)
                OR (l.subject_type = 'capital_transaction' AND l.subject_id IN (SELECT id FROM partner_capital_transactions WHERE partner_id = ?))
                OR (l.subject_type = 'partner_document' AND l.subject_id IN (SELECT id FROM partner_documents WHERE partner_id = ?))
             ORDER BY l.id DESC LIMIT 100",
            [$pid, $pid, $pid]
        );
    }

    /** @param array<string, mixed> $partner */
    private function back(array $partner, string $message): Response
    {
        return $this->redirectRoute('partners.show', ['id' => $partner['id']])->with('success', $message);
    }

    /** @return array<string, string> */
    public static function rules(?int $exceptId): array
    {
        return [
            'name' => 'required|string|min:2|max:150',
            'email' => 'nullable|email|max:191',
            'mobile' => 'nullable|string|regex:/^(\+?\d{1,3}[\s-]?)?[6-9]\d{9}$|^\+?[0-9][0-9\s-]{6,18}$/',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'pin_code' => 'nullable|string|regex:/^[1-9][0-9]{5}$|^[A-Za-z0-9 -]{3,12}$/',
            'pan_number' => 'nullable|string|regex:/^[A-Za-z]{3}[PFCHATBLJGpfchatbljg][A-Za-z][0-9]{4}[A-Za-z]$/',
            'date_of_joining' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:5000',
        ];
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return ['pan_number' => 'PAN', 'pin_code' => 'PIN code', 'date_of_joining' => 'Date of joining', 'mobile' => 'Mobile number'];
    }
}
