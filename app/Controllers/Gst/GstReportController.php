<?php

declare(strict_types=1);

namespace App\Controllers\Gst;

use App\Core\App;
use App\Core\Controller;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export\TableExport;
use App\Services\Gst\GstMasterService;
use App\Services\Gst\GstRegistrationService;
use App\Services\Gst\GstReportService;

/** GST registration reports with search, filters, sorting, pagination and CSV / Excel / PDF export. */
final class GstReportController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('gst/reports/index', [
            'title' => 'GST registration reports',
            'breadcrumbs' => ['GST Management' => route('gst.dashboard'), 'Reports' => null],
            'reports' => GstReportService::REPORTS,
        ]);
    }

    public function show(Request $request, string $report): Response
    {
        $me = $this->user();
        $filters = $this->filters($request);
        $result = (new GstReportService(App::db()))->run($me, $report, $filters);
        $per = (int) setting('records_per_page', 15) * 2;
        $page = max(1, (int) $request->input('page', 1));
        $masters = new GstMasterService(App::db());
        $partners = [];
        if (can('partners.view')) {
            [$w, $p] = App::dataAccess()->constrain($me, 'partners', 'p');
            $partners = App::db()->select("SELECT p.id, p.partner_code, p.name FROM partners p WHERE {$w} ORDER BY p.name", $p);
        }
        return $this->view('gst/reports/show', [
            'title' => $result['title'],
            'breadcrumbs' => ['GST Management' => route('gst.dashboard'), 'Reports' => route('gst.reports'), $result['title'] => null],
            'key' => $report,
            'result' => $result,
            'rows' => new Paginator(array_slice($result['rows'], ($page - 1) * $per, $per), count($result['rows']), $page, $per),
            'filters' => $filters,
            'states' => $masters->states(),
            'types' => $masters->types(),
            'partners' => $partners,
            'statuses' => GstRegistrationService::STATUSES,
            'canExport' => can('gst_registrations.export'),
        ]);
    }

    public function export(Request $request, string $report): Response
    {
        $me = $this->user();
        $format = $request->string('format', 'csv');
        $filters = $this->filters($request);
        $result = (new GstReportService(App::db()))->run($me, $report, $filters);
        App::activity()->log('gst.report_exported', "Exported GST report \"{$result['title']}\" as {$format} (" . count($result['rows']) . ' rows)', module: 'gst_registrations',
            newValues: ['report' => $report, 'format' => $format, 'filters' => array_filter($filters), 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'gst-' . $report);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        $f = [];
        foreach (['q', 'status', 'from', 'to', 'sort', 'archived', 'mapping'] as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
        }
        foreach (['state', 'type', 'partner'] as $k) {
            $f[$k] = max(0, (int) $request->input($k, 0)) ?: '';
        }
        return $f;
    }
}
