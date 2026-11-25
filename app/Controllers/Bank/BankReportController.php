<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Bank\BankAccountService;
use App\Services\Bank\BankLedgerService;
use App\Services\Bank\BankReportService;
use App\Services\Bank\BankTransactionService;
use App\Services\Export\TableExport;

/** The eleven bank reports with filters, pagination and CSV / Excel / PDF export. */
final class BankReportController extends BankController
{
    private const TEXT = ['q', 'status', 'from', 'to', 'bank', 'txn_type', 'direction', 'mapping', 'archived'];
    private const INTS = ['account', 'type', 'partner', 'gst', 'activity'];

    public function index(Request $request): Response
    {
        return $this->view('bank/reports/index', ['title' => 'Bank reports', 'breadcrumbs' => self::CRUMB + ['Reports' => null], 'reports' => BankReportService::REPORTS]);
    }

    public function show(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::TEXT, self::INTS);
        $result = (new BankReportService(App::db()))->run($this->user(), $report, $f);
        $per = (int) setting('records_per_page', 15) * 2;
        $page = max(1, (int) $request->input('page', 1));
        $accSvc = new BankAccountService(App::db());
        return $this->view('bank/reports/show', [
            'title' => $result['title'], 'breadcrumbs' => self::CRUMB + ['Reports' => route('bank.reports'), $result['title'] => null],
            'key' => $report, 'result' => $result, 'f' => $f,
            'rows' => new Paginator(array_slice($result['rows'], ($page - 1) * $per, $per), count($result['rows']), $page, $per),
            'accounts' => $this->accountOptions(), 'banks' => $accSvc->bankNames($this->scope()), 'types' => $accSvc->types(false), 'partners' => $this->partnerOptions(), 'gsts' => $this->gstOptions(),
            'activities' => $this->activityOptions(), 'txnTypes' => BankLedgerService::TYPE_LABELS, 'txnStatuses' => BankTransactionService::STATUSES, 'accountStatuses' => BankAccountService::STATUSES,
            'canExport' => can('bank_accounts.export'),
        ]);
    }

    public function export(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::TEXT, self::INTS);
        $format = $request->string('format', 'csv');
        $result = (new BankReportService(App::db()))->run($this->user(), $report, $f);
        App::activity()->log('bank.report_exported', "Exported bank report \"{$result['title']}\" as {$format} (" . count($result['rows']) . ' rows)', module: 'bank_accounts',
            newValues: ['report' => $report, 'format' => $format, 'filters' => array_filter($f), 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'bank-' . $report);
    }
}
