<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Export\TableExport;
use App\Services\Partners\PartnerReportService;

/** Capital balances overview and the partner-wise ledger (posted entries only). */
final class CapitalLedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $this->user();
        $filters = ['status' => $request->string('status'), 'to' => $request->string('to'), 'partner' => ''];
        $report = (new PartnerReportService(App::db()))->runUnchecked($me, 'balances', $filters);
        return $this->view('capital/ledger-index', [
            'title' => 'Capital balances',
            'breadcrumbs' => ['Capital ledger' => null],
            'report' => $report,
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $pid = $this->intParam($id);
        $partner = $this->partner($me, $pid);
        $filters = ['partner' => $pid, 'from' => $request->string('from'), 'to' => $request->string('to')];
        $report = (new PartnerReportService(App::db()))->runUnchecked($me, 'ledger', $filters);
        return $this->view('capital/ledger-show', [
            'title' => 'Capital ledger — ' . $partner['name'],
            'breadcrumbs' => ['Capital ledger' => route('capital.ledger.index'), $partner['name'] => null],
            'partner' => $partner,
            'report' => $report,
            'filters' => $filters,
        ]);
    }

    public function export(Request $request, string $id): Response
    {
        $me = $this->user();
        $pid = $this->intParam($id);
        $partner = $this->partner($me, $pid);
        $format = $request->string('format', 'csv');
        $report = (new PartnerReportService(App::db()))->runUnchecked($me, 'ledger', ['partner' => $pid, 'from' => $request->string('from'), 'to' => $request->string('to')]);
        App::activity()->log('capital_ledger.exported', "Exported the capital ledger of {$partner['partner_code']} as {$format}", 'partner', $pid, module: 'capital_ledger',
            newValues: ['rows' => count($report['rows']), 'format' => $format]);
        return (new TableExport($report['title'] . ' — ' . $partner['partner_code'] . ' ' . $partner['name'], $report['columns'], $report['rows'], $report['meta'], $report['totals']))
            ->response($format, 'capital-ledger-' . $partner['partner_code']);
    }

    /** Partner inside the user's capital_ledger scope, else 404. @return array<string, mixed> */
    private function partner(array $me, int $pid): array
    {
        [$w, $p] = App::dataAccess()->constrainAs($me, 'capital_ledger', 'partners', 'p');
        $partner = App::db()->first("SELECT p.id, p.partner_code, p.name, p.status FROM partners p WHERE p.id = ? AND {$w}", array_merge([$pid], $p));
        return $partner ?? throw new HttpException(404, 'Partner not found.');
    }
}
