<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

use App\Core\App;
use App\Core\Controller;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export\TableExport;
use App\Services\Partners\PartnerReportService;
use App\Services\Partners\PartnerService;

/** The eight partner reports with filters, pagination and CSV / Excel / PDF export. */
final class PartnerReportController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('partner-reports/index', [
            'title' => 'Partner reports',
            'breadcrumbs' => ['Partner reports' => null],
            'reports' => (new PartnerReportService(App::db()))->available($this->user()),
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $me = $this->user();
        $svc = new PartnerReportService(App::db());
        $filters = $this->filters($request);
        $result = $svc->run($me, $report, $filters);
        $per = (int) setting('records_per_page', 15) * 2;
        $page = max(1, (int) $request->input('page', 1));
        $module = PartnerReportService::REPORTS[$report][1];
        return $this->view('partner-reports/show', [
            'title' => $result['title'],
            'breadcrumbs' => ['Partner reports' => route('partner-reports.index'), $result['title'] => null],
            'key' => $report,
            'result' => $result,
            'rows' => new Paginator(array_slice($result['rows'], ($page - 1) * $per, $per), count($result['rows']), $page, $per),
            'filters' => $filters,
            'partners' => (new PartnerService(App::db()))->options($module === 'profit_sharing' || $module === 'partners'
                ? App::dataAccess()->constrain($me, 'partners', 'p') : App::dataAccess()->constrainAs($me, $module, 'partners', 'p'), true),
            'canExport' => can('partner_reports.export') && can($module . '.export'),
        ]);
    }

    public function export(Request $request, string $report): Response
    {
        $me = $this->user();
        $svc = new PartnerReportService(App::db());
        $svc->authorize($me, $report, export: true);
        $format = $request->string('format', 'csv');
        $filters = $this->filters($request);
        $result = $svc->run($me, $report, $filters);
        App::activity()->log('partner_reports.exported', "Exported report \"{$result['title']}\" as {$format} (" . count($result['rows']) . ' rows)', module: 'partner_reports',
            newValues: ['report' => $report, 'format' => $format, 'filters' => $filters, 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'report-' . $report);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $f = ['partner' => (int) $request->input('partner', 0) ?: '', 'status' => $request->string('status'), 'from' => $request->string('from'), 'to' => $request->string('to'), 'q' => mb_substr($request->string('q'), 0, 100)];
        foreach (['from', 'to'] as $k) {
            if ($f[$k] !== '' && !\App\Core\Validator::isDate($f[$k])) {
                $f[$k] = '';
            }
        }
        return $f;
    }
}
