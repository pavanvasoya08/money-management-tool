<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Bank\BankStatementService;

/** Statements: list, upload, details with import history, CSV column mapping → preview → import, download, archive. */
final class BankStatementController extends BankController
{
    private BankStatementService $statements;

    public function __construct()
    {
        $this->statements = new BankStatementService(App::db());
    }

    public function index(Request $request): Response
    {
        $account = (int) $request->input('account', 0) ?: null;
        if ($account !== null) {
            $this->findAccount($account);
        }
        return $this->view('bank/statements/index', [
            'title' => 'Bank statements', 'breadcrumbs' => self::CRUMB + ['Statements' => null],
            'statements' => $this->statements->list($this->scope(), $account, $request->string('archived') === '1'), 'accounts' => array_filter($this->accountOptions(), static fn (array $a): bool => true),
            'account' => $account, 'showArchived' => $request->string('archived') === '1',
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('bank_accounts.manage_statements');
        $acc = $this->findAccount((int) $request->input('bank_account_id', 0));
        $file = $request->file('statement') ?? throw ValidationException::withMessage('statement', 'Choose the statement file.');
        $id = $this->statements->upload($acc, $file, $request->all(), $this->user());
        $isCsv = str_ends_with(strtolower((string) $file['name']), '.csv');
        if ($isCsv && can('bank_accounts.import_statements')) {
            return $this->redirectRoute('bank.statements.import', ['id' => $id])->with('success', 'Statement uploaded. Map the CSV columns to import its lines.');
        }
        return $this->redirectRoute('bank.statements.show', ['id' => $id])->with('success', 'Statement uploaded.');
    }

    public function show(Request $request, string $id): Response
    {
        $st = $this->find($id);
        $imports = $this->statements->imports((int) $st['id']);
        $current = null;
        foreach ($imports as $i) {
            if (in_array($i['status'], ['preview', 'imported'], true)) {
                $current = $i;
                break;
            }
        }
        return $this->view('bank/statements/show', [
            'title' => 'Statement ' . format_date($st['period_from']) . ' – ' . format_date($st['period_to']),
            'breadcrumbs' => self::CRUMB + ['Statements' => route('bank.statements.index'), $st['account_code'] => route('bank.show', ['id' => $st['bank_account_id'], 'tab' => 'statements']), 'Statement #' . $st['id'] => null],
            'st' => $st, 'imports' => $imports, 'current' => $current, 'rows' => $current !== null ? $this->statements->rows((int) $current['id']) : [],
            'amounts' => App::fields()->access($this->user(), 'bank_accounts', 'current_balance'),
        ]);
    }

    /** Column-mapping screen for a CSV statement. */
    public function import(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.import_statements');
        $st = $this->find($id);
        $delimiter = (string) $request->input('delimiter', ',');
        if (!array_key_exists($delimiter, BankStatementService::DELIMITERS)) {
            $delimiter = ',';
        }
        $sample = str_ends_with(strtolower((string) $st['original_name']), '.csv') ? $this->statements->sample($st, $delimiter) : [];
        return $this->view('bank/statements/import', [
            'title' => 'Import statement lines', 'breadcrumbs' => self::CRUMB + ['Statements' => route('bank.statements.index'), 'Statement #' . $st['id'] => route('bank.statements.show', ['id' => $st['id']]), 'Import' => null],
            'st' => $st, 'sample' => $sample, 'delimiter' => $delimiter, 'guess' => $sample !== [] ? BankStatementService::guessMap($sample[0]) : [],
            'columns' => $sample !== [] ? max(array_map('count', $sample)) : 0,
            'guessFormat' => $sample !== [] ? BankStatementService::guessDateFormat(array_slice($sample, 1), BankStatementService::guessMap($sample[0])['date']) : null,
        ]);
    }

    public function preview(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.import_statements');
        $st = $this->find($id);
        $this->statements->preview($st, ['map' => (array) $request->input('map', []), 'date_format' => $request->string('date_format'), 'delimiter' => (string) $request->input('delimiter', ','),
            'has_header' => $request->string('has_header') === '1'], $this->user());
        return $this->redirectRoute('bank.statements.show', ['id' => $st['id']])->with('info', 'Preview ready. Check the lines, then import. Nothing is posted to the ledger.');
    }

    public function confirm(Request $request, string $id, string $import): Response
    {
        $this->authorize('bank_accounts.import_statements');
        $st = $this->find($id);
        $imp = App::db()->first('SELECT * FROM bank_statement_imports WHERE id = ? AND statement_id = ?', [$this->intParam($import), $st['id']]) ?? throw new HttpException(404, 'Import not found.');
        $this->statements->import($imp, $this->user());
        return $this->redirectRoute('bank.statements.show', ['id' => $st['id']])->with('success', 'Lines imported. Match them on the Reconciliation page.');
    }

    public function discard(Request $request, string $id, string $import): Response
    {
        $this->authorize('bank_accounts.import_statements');
        $st = $this->find($id);
        $imp = App::db()->first('SELECT * FROM bank_statement_imports WHERE id = ? AND statement_id = ?', [$this->intParam($import), $st['id']]) ?? throw new HttpException(404, 'Import not found.');
        $this->statements->discard($imp);
        return $this->redirectRoute('bank.statements.import', ['id' => $st['id']])->with('success', 'Preview discarded. Adjust the mapping and try again.');
    }

    public function download(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.download_documents');
        return $this->statements->response($this->find($id));
    }

    public function archive(Request $request, string $id): Response
    {
        $this->authorize('bank_accounts.manage_statements');
        $st = $this->find($id);
        $this->statements->archive($st, $request->string('reason'), $this->user());
        return $this->redirectRoute('bank.show', ['id' => $st['bank_account_id'], 'tab' => 'statements'])->with('success', 'Statement archived.');
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        $st = $this->statements->find($this->intParam($id)) ?? throw new HttpException(404, 'Statement not found.');
        $this->findAccount((int) $st['bank_account_id']);
        return $st;
    }
}
