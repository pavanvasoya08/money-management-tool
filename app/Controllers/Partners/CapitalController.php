<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

use App\Core\App;
use App\Core\Controller;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Services\Export\TableExport;
use App\Services\Partners\CapitalService;
use App\Services\Partners\DocumentService;
use App\Services\Partners\PartnerReportService;
use App\Services\Partners\PartnerService;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Shared screens for the three capital registers. Each subclass names its
 * transaction type; the module (capital_contributions / _withdrawals /
 * _adjustments) drives permissions, record scope and field rules.
 */
abstract class CapitalController extends Controller
{
    abstract protected function type(): string;

    /** Route-name prefix, e.g. capital.contributions */
    abstract protected function routePrefix(): string;

    /** @return array{0: string, 1: string} singular, plural */
    abstract protected function names(): array;

    protected function module(): string
    {
        return CapitalService::module($this->type());
    }

    protected function svc(): CapitalService
    {
        return new CapitalService(App::db());
    }

    public function index(Request $request): Response
    {
        $me = $this->user();
        $filters = $this->filters($request);
        $scope = App::dataAccess()->constrain($me, $this->module(), 't');
        $page = $this->svc()->paginate($this->type(), $filters, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15), $scope);
        return $this->view('capital/index', $this->common() + [
            'title' => $this->names()[1],
            'breadcrumbs' => [$this->names()[1] => null],
            'entries' => new Paginator(App::fields()->presentMany($me, $this->module(), $page->items), $page->total, $page->page, $page->perPage),
            'totals' => $this->svc()->totals($this->type(), $filters, $scope),
            'filters' => $filters,
            'partners' => (new PartnerService(App::db()))->options(App::dataAccess()->constrainAs($me, $this->module(), 'partners', 'p'), true),
            'scopeLabel' => App::dataAccess()->resolve($me, $this->module())->describe(),
            'amountAccess' => App::fields()->access($me, $this->module(), 'amount'),
            'workflow' => App::approvals()->workflow(CapitalService::TYPES[$this->type()][1]),
        ]);
    }

    public function create(Request $request): Response
    {
        $me = $this->user();
        $this->assertAmountEditable($me);
        $partners = $this->entryPartners($me);
        $selected = (int) $request->input('partner', old('partner_id', 0));
        return $this->view('capital/form', $this->common() + [
            'title' => 'New ' . strtolower($this->names()[0]),
            'breadcrumbs' => [$this->names()[1] => route($this->routePrefix() . '.index'), 'New' => null],
            'entry' => null,
            'partners' => $partners,
            'selectedPartner' => $selected,
            'availability' => $this->availability($partners, $me),
            'canAttach' => can('partners.upload_documents'),
            'workflow' => App::approvals()->workflow(CapitalService::TYPES[$this->type()][1]),
        ]);
    }

    public function store(Request $request): Response
    {
        $me = $this->user();
        $this->assertAmountEditable($me);
        $data = $this->validated($request);
        App::dataAccess()->assertCanAccess($me, 'partners', (int) $data['partner_id']);
        $svc = $this->svc();
        $id = App::db()->transaction(function () use ($svc, $data, $me, $request): int {
            $id = $svc->createDraft($this->type(), $data, $me);
            $this->attach($request, (int) $data['partner_id'], $id);
            return $id;
        });
        $message = 'Draft saved.';
        if ($request->string('then') === 'submit') {
            $txn = $svc->submit($svc->find($id), $me);
            $message = $txn['status'] === 'approved' ? 'Submitted and approved (no approval step applies). It can now be posted.' : 'Submitted for approval.';
        }
        return $this->redirectRoute($this->routePrefix() . '.show', ['id' => $id])->with('success', $message);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $txn = $this->findEntry($id);
        $svc = $this->svc();
        $approval = $txn['approval_request_id'] ? App::approvals()->request((int) $txn['approval_request_id']) : null;
        $raw = $txn;
        return $this->view('capital/show', $this->common() + [
            'title' => $txn['transaction_no'],
            'breadcrumbs' => [$this->names()[1] => route($this->routePrefix() . '.index'), $txn['transaction_no'] => null],
            'entry' => App::fields()->present($me, $this->module(), $txn),
            'raw' => $raw,
            'approval' => $approval,
            'approvalHistory' => $approval ? App::approvals()->history((int) $approval['id']) : [],
            'canDecide' => $approval && $approval['status'] === 'pending' && App::approvals()->whyNot($me, $approval) === null,
            'documents' => can('partners.view_documents') ? (new DocumentService(App::db()))->forPartner($me, (int) $txn['partner_id'], (int) $txn['id']) : [],
            'canAttach' => can('partners.upload_documents') && $txn['type'] !== 'reversal',
            'available' => $this->balanceVisible($me, (int) $txn['partner_id']) && $txn['direction'] === 'debit' && !in_array($txn['status'], ['posted', 'reversed', 'cancelled', 'rejected'], true) ? App::capitalLedger()->available((int) $txn['partner_id'], (int) $txn['id']) : null,
            'balance' => $this->balanceVisible($me, (int) $txn['partner_id']) ? App::capitalLedger()->balance((int) $txn['partner_id']) : null,
            'mayOverdraw' => $svc->mayOverdraw($me),
            'postings' => App::gate()->isSuperAdmin($me) ? App::postingQueue()->forSource(CapitalService::SUBJECT, (int) $txn['id']) : [],
            'particulars' => PartnerReportService::particulars($raw),
            'today' => BusinessDate::today(),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $me = $this->user();
        $txn = $this->findEntry($id);
        if (!in_array($txn['status'], ['draft', 'rejected'], true)) {
            return $this->redirectRoute($this->routePrefix() . '.show', ['id' => $txn['id']])->with('error', 'Only draft or rejected entries can be edited.');
        }
        $this->assertAmountEditable($me);
        $partners = $this->entryPartners($me);
        return $this->view('capital/form', $this->common() + [
            'title' => 'Edit ' . $txn['transaction_no'],
            'breadcrumbs' => [$this->names()[1] => route($this->routePrefix() . '.index'), $txn['transaction_no'] => route($this->routePrefix() . '.show', ['id' => $txn['id']]), 'Edit' => null],
            'entry' => $txn,
            'partners' => $partners,
            'selectedPartner' => (int) $txn['partner_id'],
            'availability' => $this->availability($partners, $me, (int) $txn['id']),
            'canAttach' => false,
            'workflow' => App::approvals()->workflow(CapitalService::TYPES[$this->type()][1]),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->user();
        $txn = $this->findEntry($id);
        $this->assertAmountEditable($me);
        $data = $this->validated($request);
        App::dataAccess()->assertCanAccess($me, 'partners', (int) $data['partner_id']);
        $this->svc()->updateDraft($txn, $data, $me);
        return $this->redirectRoute($this->routePrefix() . '.show', ['id' => $txn['id']])->with('success', $txn['status'] === 'rejected' ? 'Changes saved. The entry is a draft again and can be resubmitted.' : 'Draft updated.');
    }

    public function submit(Request $request, string $id): Response
    {
        $txn = $this->svc()->submit($this->findEntry($id), $this->user());
        return $this->redirectRoute($this->routePrefix() . '.show', ['id' => $txn['id']])
            ->with('success', $txn['status'] === 'approved' ? 'Approved automatically (no approval step applies). It can now be posted.' : 'Submitted for approval.');
    }

    public function cancel(Request $request, string $id): Response
    {
        $txn = $this->findEntry($id);
        $data = $this->validate($request, ['reason' => 'nullable|string|max:255']);
        $this->svc()->cancel($txn, $this->user(), $data['reason']);
        return $this->redirectRoute($this->routePrefix() . '.show', ['id' => $txn['id']])->with('success', 'Entry cancelled. It will never affect capital balances.');
    }

    public function post(Request $request, string $id): Response
    {
        $txn = $this->findEntry($id);
        $this->svc()->post($txn, $this->user());
        return $this->redirectRoute($this->routePrefix() . '.show', ['id' => $txn['id']])->with('success', "{$txn['transaction_no']} posted to the capital ledger.");
    }

    public function reverse(Request $request, string $id): Response
    {
        $txn = $this->findEntry($id);
        $data = $this->validate($request, ['reason' => 'required|string|min:5|max:500', 'reversal_date' => 'required|date|before_or_equal:today'], ['reversal_date' => 'Reversal date']);
        $revId = $this->svc()->reverse($txn, (string) $data['reason'], (string) $data['reversal_date'], $this->user());
        return $this->redirectRoute($this->routePrefix() . '.show', ['id' => $revId])->with('success', "{$txn['transaction_no']} was reversed. Record a replacement entry if a corrected amount is needed.");
    }

    public function export(Request $request): Response
    {
        $me = $this->user();
        $format = $request->string('format', 'csv');
        $key = $this->type() === 'contribution' ? 'contributions' : ($this->type() === 'withdrawal' ? 'withdrawals' : null);
        $filters = $this->filters($request);
        if ($key === null) {
            $result = $this->adjustmentExport($me, $filters);
        } else {
            $result = (new PartnerReportService(App::db()))->runUnchecked($me, $key, $filters);
        }
        App::activity()->log($this->module() . '.exported', 'Exported ' . count($result['rows']) . ' ' . strtolower($this->names()[1]) . " as {$format}", module: $this->module(),
            newValues: ['rows' => count($result['rows']), 'format' => $format, 'filters' => $filters, 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, str_replace('.', '-', $this->routePrefix()));
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    protected function common(): array
    {
        return ['type' => $this->type(), 'module' => $this->module(), 'prefix' => $this->routePrefix(), 'names' => $this->names()];
    }

    /** Entry inside the user's record scope and belonging to this register (404 otherwise). @return array<string, mixed> */
    protected function findEntry(string $id): array
    {
        $tid = $this->intParam($id);
        App::dataAccess()->assertCanAccess($this->user(), $this->module(), $tid);
        $txn = $this->svc()->find($tid) ?? throw new HttpException(404, 'Entry not found.');
        $belongs = $txn['type'] === $this->type()
            || ($txn['type'] === 'reversal' && ($txn['reversal_of_type'] ?? null) === $this->type());
        if (!$belongs) {
            throw new HttpException(404, 'Entry not found.');
        }
        return $txn;
    }

    /** @return array<string, mixed> */
    protected function filters(Request $request): array
    {
        $f = ['partner' => (int) $request->input('partner', 0) ?: '', 'status' => $request->string('status'), 'from' => $request->string('from'), 'to' => $request->string('to'), 'q' => mb_substr($request->string('q'), 0, 100)];
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && !\App\Core\Validator::isDate($f[$k])) {
                $f[$k] = '';
            }
        }
        return $f;
    }

    /** @return array<string, mixed> validated input with amount in paise */
    protected function validated(Request $request): array
    {
        $rules = [
            'partner_id' => 'required|integer',
            'amount' => 'required|money',
            'transaction_date' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:5000',
        ];
        $rules += match ($this->type()) {
            'contribution' => ['payment_method' => 'required|in:' . implode(',', array_keys(CapitalService::METHODS)), 'payment_reference' => 'nullable|string|max:100', 'payment_source_ref' => 'nullable|string|max:150', 'reason' => 'nullable|string|max:500'],
            'withdrawal' => ['payment_method' => 'required|in:' . implode(',', array_keys(CapitalService::METHODS)), 'payment_reference' => 'nullable|string|max:100', 'payment_source_ref' => 'nullable|string|max:150', 'reason' => 'required|string|min:3|max:500'],
            'adjustment' => ['adjustment_category' => 'required|in:' . implode(',', array_keys(CapitalService::CATEGORIES)), 'direction' => 'required|in:credit,debit', 'reason' => 'required|string|min:5|max:500'],
        };
        $data = $this->validate($request, $rules, [
            'partner_id' => 'Partner', 'transaction_date' => 'Date', 'payment_method' => 'Payment method', 'payment_reference' => 'Payment reference',
            'payment_source_ref' => 'Bank / cash reference', 'adjustment_category' => 'Adjustment type', 'direction' => 'Effect',
        ]);
        $data['amount'] = Money::parse((string) $data['amount']);
        return $data;
    }

    /** Partners the user may record entries for: active and inside the module's partner scope. @return list<array<string, mixed>> */
    protected function entryPartners(array $me): array
    {
        $rows = (new PartnerService(App::db()))->options(App::dataAccess()->constrain($me, 'partners', 'p'));
        return array_values(array_filter($rows, static fn (array $p): bool => $p['status'] === 'active'));
    }

    /** Available capital per partner for debit entries (shown as a warning before submission). @return array<int, array{balance: int, available: int}> */
    protected function availability(array $partners, array $me, ?int $exceptId = null): array
    {
        if ($this->type() === 'contribution' || !can('capital_ledger.view')) {
            return [];
        }
        $out = [];
        foreach ($partners as $p) {
            if (!$this->balanceVisible($me, (int) $p['id'])) {
                continue;
            }
            $out[(int) $p['id']] = ['balance' => App::capitalLedger()->balance((int) $p['id']), 'available' => App::capitalLedger()->available((int) $p['id'], $exceptId)];
        }
        return $out;
    }

    /** Balance shown only with ledger permission, the partner inside the ledger record scope, and a visible balance field. */
    protected function balanceVisible(array $me, int $partnerId): bool
    {
        if (!can('capital_ledger.view') || !App::fields()->access($me, 'capital_ledger', 'running_balance')->showsRealValue()) {
            return false;
        }
        [$w, $p] = App::dataAccess()->constrainAs($me, 'capital_ledger', 'partners', 'p');
        return (bool) App::db()->scalar("SELECT 1 FROM partners p WHERE p.id = ? AND {$w}", array_merge([$partnerId], $p));
    }

    /** Users who cannot see the real amount cannot create or edit entries (they would overwrite blind). */
    protected function assertAmountEditable(array $me): void
    {
        if (!App::fields()->access($me, $this->module(), 'amount')->showsRealValue()) {
            throw new AuthorizationException($this->module() . '.amount', 'You cannot record entries because the amount field is hidden or masked for you.');
        }
    }

    protected function attach(Request $request, int $partnerId, int $txnId): void
    {
        $file = $request->file('document');
        if ($file === null) {
            return;
        }
        if (!can('partners.upload_documents')) {
            throw new AuthorizationException('partners.upload_documents', 'You are not allowed to upload documents.');
        }
        $category = ['contribution' => 'contribution_proof', 'withdrawal' => 'withdrawal_proof', 'adjustment' => 'adjustment_proof'][$this->type()];
        (new DocumentService(App::db()))->store($partnerId, $file, $category, $request->string('document_title') ?: DocumentService::CATEGORIES[$category], $this->user(), $txnId);
    }

    /** @param array<string, mixed> $me @param array<string, mixed> $filters */
    private function adjustmentExport(array $me, array $filters): array
    {
        $module = $this->module();
        $rows = $this->svc()->all('adjustment', $filters, App::dataAccess()->constrain($me, $module, 't'));
        $amount = App::fields()->access($me, $module, 'amount');
        $columns = ['transaction_no' => ['label' => 'Number'], 'transaction_date' => ['label' => 'Date'], 'partner' => ['label' => 'Partner'], 'category' => ['label' => 'Type'],
            'effect' => ['label' => 'Effect'], 'amount' => ['label' => 'Amount', 'type' => 'money'], 'reason' => ['label' => 'Reason'], 'status' => ['label' => 'Status'],
            'created_by_name' => ['label' => 'Created by'], 'approved_by_name' => ['label' => 'Approved by']];
        if (!$amount->canView()) {
            unset($columns['amount']);
        }
        $out = [];
        foreach ($rows as $r) {
            $a = Money::fromDb($r['amount']);
            $out[] = ['transaction_no' => $r['transaction_no'], 'transaction_date' => BusinessDate::format($r['transaction_date']), 'partner' => $r['partner_code'] . ' ' . $r['partner_name'],
                'category' => ($r['type'] === 'reversal' ? 'Reversal of ' : '') . (CapitalService::CATEGORIES[$r['adjustment_category']] ?? ''), 'effect' => $r['direction'] === 'credit' ? 'Increase' : 'Decrease',
                'amount' => $amount->showsRealValue() ? $a : \App\Services\Authorization\FieldPolicy::mask(Money::toDb($a), 'amount'),
                'reason' => $r['reason'], 'status' => CapitalService::STATUSES[$r['status']] ?? $r['status'], 'created_by_name' => $r['created_by_name'], 'approved_by_name' => $r['approved_by_name'] ?? '—'];
        }
        return ['title' => 'Capital adjustments', 'columns' => $columns, 'rows' => $out, 'meta' => [App::dataAccess()->resolve($me, $module)->describe() . ' · generated by ' . $me['name']], 'totals' => null];
    }
}
