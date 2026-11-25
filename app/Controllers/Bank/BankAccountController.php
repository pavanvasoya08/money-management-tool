<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Bank\BankAccountService;
use App\Services\Bank\BankDashboardService;
use App\Services\Bank\BankDocumentService;
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankReconciliationService;
use App\Services\Bank\BankRelationService;
use App\Services\Bank\BankStatementService;
use App\Services\Bank\BankTransactionService;
use App\Services\Bank\BankTransferService;
use App\Services\Export\TableExport;
use App\Support\Money;

/** Bank dashboard, account register, add/edit, the 12-tab account profile and lifecycle actions. */
final class BankAccountController extends BankController
{
    /** tab => [label, permission or null] */
    public const TABS = [
        'overview' => ['Overview', null], 'information' => ['Account information', null], 'transactions' => ['Transactions', null], 'transfers' => ['Transfers', null],
        'partners' => ['Partner associations', null], 'gst' => ['GST associations', null], 'activities' => ['Business activities', null], 'statements' => ['Statements', null],
        'reconciliation' => ['Reconciliation', null], 'documents' => ['Documents', null], 'settings' => ['Settings', null], 'history' => ['History', 'bank_accounts.view_history'],
    ];
    /** Fields protected by field rules: users who may not edit them cannot submit them. */
    private const PROTECTED_FIELDS = ['account_number', 'ifsc_code', 'micr_code', 'account_holder_name', 'branch_name', 'opening_balance', 'internal_notes'];

    private BankAccountService $accounts;

    public function __construct()
    {
        $this->accounts = new BankAccountService(App::db());
    }

    public function dashboard(Request $request): Response
    {
        $me = $this->user();
        $f = $this->filters($request, ['bank', 'status', 'from', 'to'], ['type', 'partner', 'gst', 'activity']);
        return $this->view('bank/dashboard', [
            'title' => 'Bank Management', 'breadcrumbs' => ['Bank Management' => null],
            'd' => (new BankDashboardService(App::db()))->data($f + ['archived' => ''], $this->scope()),
            'f' => $f, 'scopeLabel' => App::dataAccess()->resolve($me, 'bank_accounts')->describe(),
            'banks' => $this->accounts->bankNames($this->scope()), 'types' => $this->accounts->types(false), 'partners' => $this->partnerOptions(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(),
            'fields' => App::fields()->accessMap($me, 'bank_accounts'), 'withCharts' => true,
        ]);
    }

    public function index(Request $request): Response
    {
        $me = $this->user();
        $f = $this->filters($request, ['q', 'status', 'bank', 'archived', 'sort', 'central'], ['type', 'partner', 'gst', 'activity']);
        $per = (int) setting('records_per_page', 15);
        return $this->view('bank/index', [
            'title' => 'Bank accounts', 'breadcrumbs' => self::CRUMB + ['Accounts' => null],
            'accounts' => $this->accounts->paginate($f, max(1, (int) $request->input('page', 1)), $per, $this->scope()),
            'f' => $f, 'banks' => $this->accounts->bankNames($this->scope()), 'types' => $this->accounts->types(false),
            'partners' => $this->partnerOptions(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(), 'fields' => App::fields()->accessMap($me, 'bank_accounts'),
        ]);
    }

    public function export(Request $request): Response
    {
        $me = $this->user();
        $f = $this->filters($request, ['q', 'status', 'bank', 'archived', 'sort', 'central'], ['type', 'partner', 'gst', 'activity']);
        $result = (new \App\Services\Bank\BankReportService(App::db()))->run($me, 'summary', $f);
        $format = $request->string('format', 'csv');
        App::activity()->log('bank.accounts_exported', "Exported the bank account list as {$format} (" . count($result['rows']) . ' rows)', module: 'bank_accounts', newValues: ['format' => $format, 'filters' => array_filter($f)]);
        return (new TableExport('Bank accounts', $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'bank-accounts');
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function store(Request $request): Response
    {
        $me = $this->user();
        App::fields()->assertWritable($me, 'bank_accounts', array_intersect_key($request->all(), array_flip(self::PROTECTED_FIELDS)));
        [$id, $change] = $this->accounts->create($request->all(), $me, $request->string('action') === 'submit');
        $msg = match (true) {
            $change === null => 'Draft saved. Submit it for approval when the details are complete.',
            $change['status'] === 'approved' => 'Bank account added and active.',
            default => 'Bank account added and sent for approval. It becomes active once approved.',
        };
        return $this->redirectRoute('bank.show', ['id' => $id])->with('success', $msg);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $acc = $this->findAccount($id);
        $aid = (int) $acc['id'];
        $tabs = array_filter(self::TABS, static fn (array $t): bool => $t[1] === null || can($t[1]));
        $tab = $request->string('tab', 'overview');
        if (!isset($tabs[$tab])) {
            $tab = 'overview';
        }
        $ledger = new BankLedgerService(App::db());
        $rel = new BankRelationService(App::db());
        $fields = App::fields()->accessMap($me, 'bank_accounts');
        $data = [
            'title' => $acc['display_name'], 'breadcrumbs' => self::CRUMB + ['Accounts' => route('bank.index'), $acc['account_code'] => null],
            'acc' => $this->present($acc), 'raw' => $acc, 'tab' => $tab, 'tabs' => array_map(static fn (array $t): string => $t[0], $tabs), 'fields' => $fields,
            'breakdown' => $ledger->breakdown($aid), 'available' => $ledger->available($aid), 'pending' => $this->accounts->pendingChange($aid),
            'transitions' => array_filter(BankAccountService::TRANSITIONS, static fn (array $t, string $to): bool => in_array($acc['status'], $t[0], true)
                && can('bank_accounts.' . ($t[1] ?? ($acc['status'] === 'frozen' ? 'freeze' : 'activate'))), ARRAY_FILTER_USE_BOTH),
            'counts' => [
                'partners' => (int) App::db()->scalar("SELECT COUNT(*) FROM bank_account_partner_mappings WHERE bank_account_id = ? AND status = 'active'", [$aid]),
                'gst' => (int) App::db()->scalar("SELECT COUNT(*) FROM bank_account_gst_mappings WHERE bank_account_id = ? AND status = 'active'", [$aid]),
                'activities' => (int) App::db()->scalar("SELECT COUNT(*) FROM bank_account_business_mappings WHERE bank_account_id = ? AND status = 'active'", [$aid]),
                'documents' => (int) App::db()->scalar("SELECT COUNT(*) FROM bank_account_documents WHERE bank_account_id = ? AND status = 'active'", [$aid]),
                'open' => (int) App::db()->scalar("SELECT COUNT(*) FROM bank_transactions WHERE bank_account_id = ? AND status IN ('draft', 'pending_approval', 'approved')", [$aid]),
            ],
        ];
        $data += match ($tab) {
            'overview' => ['central' => (int) $acc['is_central_funding'] === 1 ? (new BankDashboardService(App::db()))->central([$aid]) : null,
                'recent' => array_slice(array_reverse($ledger->ledger($aid, null, null)['rows']), 0, 6), 'lastRec' => (new BankReconciliationService(App::db()))->lastCompleted([$aid])[$aid] ?? null,
                'monthly' => $ledger->monthly([$aid], 6)],
            'information' => ['fullNumberAllowed' => can('bank_accounts.view_full_account_number') && ($fields['account_number'] ?? null)?->showsRealValue()],
            'transactions' => $this->ledgerTab($request, $aid, $ledger),
            'transfers' => ['transfers' => (new BankTransferService(App::db()))->paginate(['account' => $aid] + $this->filters($request, ['status', 'from', 'to'], []), max(1, (int) $request->input('page', 1)), 20, $this->scope('fa'), $this->scope('ta'))],
            'partners' => ['mappings' => $rel->partnerMappings($aid), 'partnerOptions' => $this->partnerOptions(), 'relationships' => BankRelationService::RELATIONSHIPS],
            'gst' => ['mappings' => $rel->gstMappings($aid), 'gstOptions' => $this->gstOptions(), 'designations' => BankRelationService::DESIGNATIONS],
            'activities' => ['mappings' => $rel->activityMappings($aid), 'activities' => $this->activityOptions()],
            'statements' => ['statements' => (new BankStatementService(App::db()))->list($this->scope(), $aid, $request->string('archived') === '1'), 'showArchived' => $request->string('archived') === '1'],
            'reconciliation' => ['reconciliations' => (new BankReconciliationService(App::db()))->list($this->scope(), $aid),
                'statements' => (new BankStatementService(App::db()))->list($this->scope(), $aid)],
            'documents' => ['documents' => (new BankDocumentService(App::db()))->forAccount($aid, $request->string('archived') === '1'), 'docTypes' => BankDocumentService::TYPES, 'showArchived' => $request->string('archived') === '1'],
            'settings' => ['central' => $this->accounts->central()],
            'history' => ['history' => $this->accounts->historyFor($aid), 'changes' => $this->accounts->changes($aid), 'audit' => $this->audit($aid)],
            default => [],
        };
        return $this->view('bank/show', $data);
    }

    /** Full account number on request; permission- and field-rule-checked, and logged. */
    public function reveal(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $number = $this->accounts->fullNumber($acc, $this->user());
        if ($number === null) {
            return Response::json(['error' => 'You may not view the full account number.'], 403);
        }
        App::activity()->log('bank.account_number_viewed', "Viewed the full account number of {$acc['account_code']}", 'bank_account', (int) $acc['id'], module: 'bank_accounts');
        return Response::json(['number' => $number]);
    }

    public function edit(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $this->accounts->assertWritable($acc);
        return $this->form($acc);
    }

    public function update(Request $request, string $id): Response
    {
        $me = $this->user();
        $acc = $this->findAccount($id);
        $input = $request->all();
        // A protected field that is masked or read-only for this user keeps its stored value.
        foreach (self::PROTECTED_FIELDS as $k) {
            $access = App::fields()->access($me, 'bank_accounts', $k);
            if (!$access->canEdit()) {
                unset($input[$k]);
            }
        }
        $input += ['account_number' => '', 'ifsc_code' => $acc['ifsc_code'], 'micr_code' => $acc['micr_code'], 'account_holder_name' => $acc['account_holder_name'], 'branch_name' => $acc['branch_name'],
            'opening_balance' => Money::toDb(Money::fromDb($acc['opening_balance'])), 'internal_notes' => $acc['internal_notes']];
        $r = $this->accounts->update($acc, $input, $me, $request->string('change_reason') ?: null);
        $msg = match (true) {
            $r['key'] !== [] && ($r['change']['status'] ?? '') !== 'approved' => 'Key details sent for approval: ' . implode(', ', array_map(static fn (string $k): string => str_replace(['_', 'account number encrypted', 'account number hash', 'account number last4'], [' ', 'account number', '', ''], $k), $r['key'])) . '. They change once approved.' . ($r['direct'] !== [] ? ' Other changes are saved.' : ''),
            $r['direct'] === [] && $r['key'] === [] => 'Nothing changed.',
            default => 'Account updated.',
        };
        return $this->redirectRoute('bank.show', ['id' => $acc['id'], 'tab' => 'information'])->with($r['key'] !== [] && ($r['change']['status'] ?? '') !== 'approved' ? 'info' : 'success', $msg);
    }

    public function submit(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $change = $this->accounts->submit($acc, $this->user());
        return $this->back($acc, $change['status'] === 'approved' ? 'Account approved and active.' : 'Account sent for approval.', $change['status'] === 'approved' ? 'success' : 'info');
    }

    public function status(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $data = $this->validate($request, ['status' => 'required|in:active,inactive,frozen,closed', 'reason' => 'nullable|string|max:255', 'effective_date' => 'nullable|date'], ['effective_date' => 'Date']);
        $to = (string) $data['status'];
        $perm = BankAccountService::TRANSITIONS[$to][1] ?? ($acc['status'] === 'frozen' ? 'freeze' : 'activate');
        $this->authorize('bank_accounts.' . $perm);
        $change = $this->accounts->changeStatus($acc, $to, $data['reason'] ?? null, $data['effective_date'] ?? null, $this->user());
        if ($change !== null) {
            return $this->back($acc, $change['status'] === 'approved' ? 'Account closed.' : 'Closure sent for approval. The account stays as it is until approved.', $change['status'] === 'approved' ? 'success' : 'info');
        }
        return $this->back($acc, 'Status changed to ' . strtolower(BankAccountService::STATUSES[$to]) . '.');
    }

    public function central(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $data = $this->validate($request, ['central' => 'required|in:0,1', 'reason' => 'required|string|min:5|max:500']);
        $change = $this->accounts->requestCentral($acc, $data['central'] === '1', (string) $data['reason'], $this->user());
        $done = $change['status'] === 'approved';
        return $this->redirectRoute('bank.show', ['id' => $acc['id'], 'tab' => 'settings'])->with($done ? 'success' : 'info',
            $done ? ($data['central'] === '1' ? 'This is now the central funding account.' : 'Central designation removed.') : 'Central-account change sent for approval.');
    }

    public function archive(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $data = $this->validate($request, ['reason' => 'required|string|max:255']);
        $this->accounts->archive($acc, (string) $data['reason'], $this->user());
        return $this->back($acc, 'Account archived. It is read-only; its history is kept.');
    }

    public function restore(Request $request, string $id): Response
    {
        $acc = $this->findAccount($id);
        $this->accounts->restore($acc, $this->user());
        return $this->back($acc, 'Account restored.');
    }

    // ---------------------------------------------------------------- helpers

    /** @param array<string, mixed>|null $acc */
    private function form(?array $acc): Response
    {
        $me = $this->user();
        return $this->view('bank/form', [
            'title' => $acc === null ? 'Add bank account' : 'Edit ' . $acc['display_name'],
            'breadcrumbs' => self::CRUMB + ['Accounts' => route('bank.index')] + ($acc === null ? ['Add' => null] : [$acc['account_code'] => route('bank.show', ['id' => $acc['id']]), 'Edit' => null]),
            'acc' => $acc !== null ? $this->present($acc) : null, 'raw' => $acc, 'types' => $this->accounts->types(), 'currencies' => BankAccountService::CURRENCIES,
            'fields' => App::fields()->accessMap($me, 'bank_accounts'), 'keyFields' => BankAccountService::KEY_FIELDS,
        ]);
    }

    /** Field-rule view of an account (the number is always masked here). @param array<string, mixed> $acc @return array<string, mixed> */
    private function present(array $acc): array
    {
        $view = $acc;
        unset($view['account_number_encrypted'], $view['account_number_hash']);
        $view['account_number'] = BankAccountService::masked($acc);
        $view['current_balance'] = Money::toDb((new BankLedgerService(App::db()))->balance((int) $acc['id']));
        return App::fields()->present($this->user(), 'bank_accounts', $view);
    }

    /** @return array<string, mixed> */
    private function ledgerTab(Request $request, int $aid, BankLedgerService $ledger): array
    {
        $f = $this->filters($request, ['from', 'to', 'view', 'status'], []);
        $txns = new BankTransactionService(App::db());
        if (($f['view'] ?? '') === 'all') {
            return ['f' => $f, 'entries' => $txns->paginate(['account' => $aid] + $f, max(1, (int) $request->input('page', 1)), 25, $this->scope())];
        }
        return ['f' => $f, 'ledger' => $ledger->ledger($aid, $f['from'] ?: null, $f['to'] ?: null)];
    }

    /** @return list<array<string, mixed>> */
    private function audit(int $aid): array
    {
        return App::db()->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id
            WHERE (l.subject_type = 'bank_account' AND l.subject_id = ?) OR (l.subject_type = 'bank_transaction' AND l.subject_id IN (SELECT id FROM bank_transactions WHERE bank_account_id = ?))
            OR (l.subject_type = 'bank_transfer' AND l.subject_id IN (SELECT id FROM bank_transfers WHERE from_account_id = ? OR to_account_id = ?)) ORDER BY l.id DESC LIMIT 150", [$aid, $aid, $aid, $aid]);
    }

    /** @param array<string, mixed> $acc */
    private function back(array $acc, string $message, string $type = 'success'): Response
    {
        return $this->redirectRoute('bank.show', ['id' => $acc['id']])->with($type, $message);
    }
}
