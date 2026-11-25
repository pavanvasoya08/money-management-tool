<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Services\Bank\BankDocumentService;
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankTransactionService;
use App\Services\Export\TableExport;
use App\Support\BusinessDate;

/** Bank transactions: list, record (deposit / withdrawal / other), detail, submit, post, reverse, cancel, capital link. */
final class BankTransactionController extends BankController
{
    private BankTransactionService $txns;

    public function __construct()
    {
        $this->txns = new BankTransactionService(App::db());
    }

    public function index(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'type', 'status', 'direction', 'from', 'to'], ['account', 'partner', 'gst', 'activity']);
        return $this->view('bank/transactions/index', [
            'title' => 'Bank transactions', 'breadcrumbs' => self::CRUMB + ['Transactions' => null],
            'entries' => $this->txns->paginate($f, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15) * 2, $this->scope()),
            'f' => $f, 'accounts' => $this->accountOptions(), 'partners' => $this->partnerOptions(), 'gsts' => $this->gstOptions(),
            'canCreate' => array_filter(array_keys(BankTransactionService::TYPES), fn (string $t): bool => BankTransactionService::mayCreate($this->user(), $t)),
            'counterparty' => App::fields()->access($this->user(), 'bank_accounts', 'counterparty_name'),
            'amounts' => App::fields()->access($this->user(), 'bank_accounts', 'current_balance'),
        ]);
    }

    public function export(Request $request): Response
    {
        $f = $this->filters($request, ['q', 'type', 'status', 'direction', 'from', 'to'], ['account', 'partner', 'gst', 'activity']);
        $result = (new \App\Services\Bank\BankReportService(App::db()))->run($this->user(), 'transactions', ['txn_type' => $f['type']] + $f);
        $format = $request->string('format', 'csv');
        App::activity()->log('bank.transactions_exported', "Exported bank transactions as {$format} (" . count($result['rows']) . ' rows)', module: 'bank_accounts', newValues: ['format' => $format, 'filters' => array_filter($f)]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'bank-transactions');
    }

    public function create(Request $request): Response
    {
        $type = $request->string('type', 'deposit');
        if (!isset(BankTransactionService::TYPES[$type])) {
            $type = 'deposit';
        }
        if (!BankTransactionService::mayCreate($this->user(), $type)) {
            throw new AuthorizationException('bank_accounts.' . BankTransactionService::TYPES[$type][3]);
        }
        return $this->form($type, null, $request);
    }

    public function store(Request $request): Response
    {
        $type = $request->string('type');
        $accountId = (int) $request->input('bank_account_id', 0);
        if ($accountId > 0) {
            $this->findAccount($accountId); // record scope
        }
        $this->guardRefs($request);
        App::fields()->assertWritable($this->user(), 'bank_accounts', array_filter(array_intersect_key($request->all(), ['counterparty_name' => 1]), static fn ($v): bool => trim((string) $v) !== ''));
        $txn = $this->txns->create($request->all(), $this->user(), $request->string('action') === 'submit');
        $this->attach($request, $txn);
        return $this->redirectRoute('bank.transactions.show', ['id' => $txn['id']])->with(...$this->outcome($txn));
    }

    public function show(Request $request, string $id): Response
    {
        $txn = $this->find($id);
        $me = $this->user();
        $request_ = $txn['approval_request_id'] ? App::db()->first('SELECT id, status FROM approval_requests WHERE id = ?', [$txn['approval_request_id']]) : null;
        return $this->view('bank/transactions/show', [
            'title' => $txn['transaction_no'], 'breadcrumbs' => self::CRUMB + ['Transactions' => route('bank.transactions.index'), $txn['transaction_no'] => null],
            'txn' => App::fields()->present($me, 'bank_accounts', $txn + ['current_balance' => $txn['amount']]), 'raw' => $txn, 'approval' => $request_,
            'documents' => (new BankDocumentService(App::db()))->forTransaction((int) $txn['id']),
            'linkable' => in_array($txn['type'], ['deposit', 'withdrawal'], true) && $txn['capital_transaction_id'] === null && can('bank_accounts.manage_transactions') && can('partners.view')
                ? $this->txns->linkableCapital($txn['type'] === 'deposit' ? 'contribution' : 'withdrawal', App::dataAccess()->constrain($me, 'partners', 'p'), (int) $txn['bank_account_id']) : [],
            'canPost' => can('bank_accounts.approve_transactions'), 'canReverse' => can('bank_accounts.reverse_transactions'),
            'canEdit' => BankTransactionService::mayCreate($me, (string) $txn['type']),
            'audit' => App::db()->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.subject_type = 'bank_transaction' AND l.subject_id = ? ORDER BY l.id DESC", [$txn['id']]),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $txn = $this->find($id);
        if (!BankTransactionService::mayCreate($this->user(), (string) $txn['type'])) {
            throw new AuthorizationException('bank_accounts.manage_transactions');
        }
        return $this->form((string) $txn['type'], $txn, $request);
    }

    public function update(Request $request, string $id): Response
    {
        $txn = $this->find($id);
        if (!BankTransactionService::mayCreate($this->user(), (string) $txn['type'])) {
            throw new AuthorizationException('bank_accounts.manage_transactions');
        }
        $this->guardRefs($request);
        App::fields()->assertWritable($this->user(), 'bank_accounts', array_intersect_key($request->all(), ['counterparty_name' => 1]), $txn);
        $txn = $this->txns->updateDraft($txn, $request->all(), $this->user());
        if ($request->string('action') === 'submit') {
            $txn = $this->txns->submit($txn, $this->user());
        }
        return $this->redirectRoute('bank.transactions.show', ['id' => $txn['id']])->with(...$this->outcome($txn));
    }

    public function submit(Request $request, string $id): Response
    {
        $txn = $this->find($id);
        if (!BankTransactionService::mayCreate($this->user(), (string) $txn['type'])) {
            throw new AuthorizationException('bank_accounts.manage_transactions');
        }
        $txn = $this->txns->submit($txn, $this->user());
        return $this->redirectRoute('bank.transactions.show', ['id' => $txn['id']])->with(...$this->outcome($txn));
    }

    public function cancel(Request $request, string $id): Response
    {
        $txn = $this->find($id);
        if (!BankTransactionService::mayCreate($this->user(), (string) $txn['type'])) {
            throw new AuthorizationException('bank_accounts.manage_transactions');
        }
        $this->txns->cancel($txn, $this->user(), $request->string('reason') ?: null);
        return $this->redirectRoute('bank.transactions.show', ['id' => $txn['id']])->with('success', 'Entry cancelled.');
    }

    /** Retry posting an approved entry that could not be posted on approval. */
    public function post(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.approve_transactions');
        $txn = $this->find($id);
        $this->txns->post($txn, $this->user());
        return $this->redirectRoute('bank.transactions.show', ['id' => $txn['id']])->with('success', 'Posted to the ledger.');
    }

    public function reverse(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.reverse_transactions');
        $txn = $this->find($id);
        $data = $this->validate($request, ['reason' => 'required|string|min:5|max:500', 'reversal_date' => 'required|date|before_or_equal:today'], ['reversal_date' => 'Reversal date']);
        $rid = $this->txns->reverse($txn, (string) $data['reason'], (string) $data['reversal_date'], $this->user());
        return $this->redirectRoute('bank.transactions.show', ['id' => $rid])->with('success', "Reversed {$txn['transaction_no']}. This is the reversal entry.");
    }

    public function linkCapital(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.manage_transactions');
        $txn = $this->find($id);
        $capitalId = (int) $request->input('capital_transaction_id', 0);
        $partner = (int) App::db()->scalar('SELECT partner_id FROM partner_capital_transactions WHERE id = ?', [$capitalId]);
        App::dataAccess()->assertCanAccess($this->user(), 'partners', $partner);
        $this->txns->linkCapital($txn, $capitalId, $this->user());
        return $this->redirectRoute('bank.transactions.show', ['id' => $txn['id']])->with('success', 'Linked to the partner capital entry. No amounts changed.');
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function find(string|int $id): array
    {
        $txn = $this->txns->find(is_int($id) ? $id : $this->intParam($id)) ?? throw new HttpException(404, 'Entry not found.');
        $this->findAccount((int) $txn['bank_account_id']);
        return $txn;
    }

    /** Partners, GST registrations and capital entries referenced must be inside the user's own scopes. */
    private function guardRefs(Request $request): void
    {
        $me = $this->user();
        if (($p = (int) $request->input('partner_id', 0)) > 0) {
            App::dataAccess()->assertCanAccess($me, 'partners', $p);
        }
        if (($g = (int) $request->input('gst_registration_id', 0)) > 0) {
            App::dataAccess()->assertCanAccess($me, 'gst_registrations', $g);
        }
        if (($c = (int) $request->input('capital_transaction_id', 0)) > 0) {
            App::dataAccess()->assertCanAccess($me, 'partners', (int) App::db()->scalar('SELECT partner_id FROM partner_capital_transactions WHERE id = ?', [$c]));
        }
    }

    /** Optional supporting document uploaded with the entry. @param array<string, mixed> $txn */
    private function attach(Request $request, array $txn): void
    {
        $file = $request->file('document');
        if ($file === null || !can('bank_accounts.manage_documents')) {
            return;
        }
        $acc = $this->findAccount((int) $txn['bank_account_id']);
        (new BankDocumentService(App::db()))->upload($acc, $file, ['document_type' => 'transaction_proof', 'title' => 'Proof for ' . $txn['transaction_no'], 'bank_transaction_id' => $txn['id']], $this->user());
    }

    /** @param array<string, mixed> $txn @return array{0: string, 1: string} */
    private function outcome(array $txn): array
    {
        return match ($txn['status']) {
            'draft' => ['success', "Saved {$txn['transaction_no']} as a draft. Submit it when ready."],
            'pending_approval' => ['info', "{$txn['transaction_no']} sent for approval. It is posted once approved."],
            'posted' => ['success', "{$txn['transaction_no']} approved and posted to the ledger."],
            'approved' => ['warning', "{$txn['transaction_no']} is approved but could not be posted yet: " . ($txn['posting_error'] ?? '')],
            default => ['success', 'Saved.'],
        };
    }

    /** @param array<string, mixed>|null $txn */
    private function form(string $type, ?array $txn, Request $request): Response
    {
        $me = $this->user();
        $account = (int) ($txn['bank_account_id'] ?? $request->input('account', 0));
        $prefill = [];
        if ($txn === null && ($rowId = (int) $request->input('statement_row', 0)) > 0) {
            $row = App::db()->first('SELECT * FROM bank_statement_rows WHERE id = ?', [$rowId]);
            if ($row !== null) {
                $this->findAccount((int) $row['bank_account_id']);
                $account = (int) $row['bank_account_id'];
                $prefill = ['statement_row_id' => $row['id'], 'transaction_date' => $row['txn_date'], 'amount' => (float) $row['debit'] > 0 ? $row['debit'] : $row['credit'], 'reference_no' => $row['reference_no'], 'description' => $row['description']];
            }
        }
        if ($txn === null && ($cap = (int) $request->input('capital', 0)) > 0) {
            $prefill['capital_transaction_id'] = $cap;
        }
        $capitalType = ['deposit' => 'contribution', 'withdrawal' => 'withdrawal'][$type] ?? null;
        return $this->view('bank/transactions/form', [
            'title' => $txn === null ? 'Record ' . strtolower(BankTransactionService::TYPES[$type][0]) : 'Edit ' . $txn['transaction_no'],
            'breadcrumbs' => self::CRUMB + ['Transactions' => route('bank.transactions.index'), ($txn === null ? 'New' : $txn['transaction_no']) => null],
            'type' => $type, 'txn' => $txn, 'prefill' => $prefill, 'accountId' => $account, 'accounts' => $this->accountOptions(true),
            'partners' => $this->partnerOptions(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(),
            'capital' => $capitalType !== null && can('partners.view') ? $this->txns->linkableCapital($capitalType, App::dataAccess()->constrain($me, 'partners', 'p')) : [],
            'types' => array_filter(BankTransactionService::TYPES, fn (array $t, string $k): bool => BankTransactionService::mayCreate($me, $k), ARRAY_FILTER_USE_BOTH),
            'available' => $account > 0 ? (new BankLedgerService(App::db()))->available($account, $txn['id'] ?? null) : null,
            'today' => BusinessDate::today(),
        ]);
    }
}
