<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Bank\BankReconciliationService;
use App\Services\Bank\BankStatementService;
use App\Support\BusinessDate;

/** Reconciliation list, start, workspace (match / suggestions / ignore), complete and reopen. */
final class BankReconciliationController extends BankController
{
    private BankReconciliationService $rec;

    public function __construct()
    {
        $this->rec = new BankReconciliationService(App::db());
    }

    public function index(Request $request): Response
    {
        $account = (int) $request->input('account', 0) ?: null;
        if ($account !== null) {
            $this->findAccount($account);
        }
        return $this->view('bank/reconciliation/index', [
            'title' => 'Bank reconciliation', 'breadcrumbs' => self::CRUMB + ['Reconciliation' => null],
            'reconciliations' => $this->rec->list($this->scope(), $account), 'accounts' => $this->accountOptions(), 'account' => $account,
            'statements' => (new BankStatementService(App::db()))->list($this->scope(), $account), 'today' => BusinessDate::today(),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('bank_accounts.manage_reconciliation');
        $acc = $this->findAccount((int) $request->input('bank_account_id', 0));
        $id = $this->rec->start($acc, $request->all(), $this->user());
        return $this->redirectRoute('bank.reconciliation.show', ['id' => $id])->with('success', 'Reconciliation started. Accept the suggested matches or match lines manually.');
    }

    public function show(Request $request, string $id): Response
    {
        $r = $this->find($id);
        $amounts = App::fields()->access($this->user(), 'bank_accounts', 'current_balance');
        return $this->view('bank/reconciliation/show', [
            'title' => $r['reconciliation_no'], 'breadcrumbs' => self::CRUMB + ['Reconciliation' => route('bank.reconciliation.index'), $r['reconciliation_no'] => null],
            'r' => $r, 'rows' => $this->rec->statementRows($r), 'ledger' => $this->rec->ledgerRows($r), 'suggestions' => $r['status'] === 'in_progress' ? $this->rec->suggestions($r) : [],
            'summary' => $this->rec->summary($r), 'amounts' => $amounts, 'canEdit' => can('bank_accounts.manage_reconciliation') && $r['status'] === 'in_progress',
            'canCreate' => can('bank_accounts.manage_transactions'),
            'history' => App::db()->select("SELECT l.*, u.name AS user_name FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.action LIKE 'bank.reconciliation%' AND l.subject_id = ? AND l.new_values LIKE ? ORDER BY l.id DESC LIMIT 100",
                [$r['bank_account_id'], '%"reconciliation_id":' . (int) $r['id'] . '%']),
        ]);
    }

    public function match(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.manage_reconciliation');
        $r = $this->find($id);
        $this->rec->match($r, (int) $request->input('statement_row_id', 0), (int) $request->input('bank_transaction_id', 0), $this->user());
        return $this->back($r, 'Matched.');
    }

    public function accept(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.manage_reconciliation');
        $r = $this->find($id);
        $n = $this->rec->acceptSuggestions($r, array_map('strval', (array) $request->input('pairs', [])), $this->user());
        return $this->back($r, $n === 0 ? 'No suggestions were selected.' : "{$n} suggested match(es) accepted.");
    }

    public function unmatch(Request $request, string $id, string $match): Response
    {
        $this->authorize('bank_accounts.manage_reconciliation');
        $r = $this->find($id);
        $this->rec->unmatch($r, $this->intParam($match), $request->string('reason'), $this->user());
        return $this->back($r, 'Match removed.');
    }

    public function ignore(Request $request, string $id, string $row): Response
    {
        $this->authorize('bank_accounts.manage_reconciliation');
        $r = $this->find($id);
        $this->rec->ignoreRow($r, $this->intParam($row), $request->string('reason'), $this->user(), $request->string('restore') !== '1');
        return $this->back($r, $request->string('restore') === '1' ? 'Line restored to unmatched.' : 'Line ignored.');
    }

    public function complete(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.manage_reconciliation');
        $r = $this->find($id);
        $this->rec->complete($r, $request->string('notes') ?: null, $this->user());
        return $this->back($r, 'Reconciliation completed.');
    }

    public function reopen(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.reopen_reconciliation');
        $r = $this->find($id);
        $change = $this->rec->requestReopen($r, $request->string('reason'), $this->user());
        return $this->back($r, $change['status'] === 'approved' ? 'Reconciliation reopened.' : 'Reopening sent for approval.', $change['status'] === 'approved' ? 'success' : 'info');
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $r = $this->rec->find($this->intParam($id)) ?? throw new HttpException(404, 'Reconciliation not found.');
        $this->findAccount((int) $r['bank_account_id']);
        return $r;
    }

    /** @param array<string, mixed> $r */
    private function back(array $r, string $msg, string $type = 'success'): Response
    {
        return $this->redirectRoute('bank.reconciliation.show', ['id' => $r['id']])->with($type, $msg);
    }
}
