<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export\TableExport;
use App\Services\Supplier\SupplierReportService;
use App\Services\Supplier\SupplierService;

/** The eight supplier reports with filters, pagination and CSV / Excel / PDF export. */
final class SupplierReportController extends SupplierBaseController
{
    private const TEXT = ['q', 'status', 'archived', 'treatment', 'as_of', 'mapping'];
    private const INTS = ['type', 'state', 'gst', 'activity', 'product'];

    public function index(Request $request): Response
    {
        return $this->view('suppliers/reports/index', ['title' => 'Supplier reports', 'breadcrumbs' => self::CRUMB + ['Reports' => null], 'reports' => SupplierReportService::REPORTS]);
    }

    public function show(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::TEXT, self::INTS);
        $result = (new SupplierReportService(App::db()))->run($this->user(), $report, $f);
        $per = (int) setting('records_per_page', 15) * 2;
        $page = max(1, (int) $request->input('page', 1));
        $svc = new SupplierService(App::db());
        return $this->view('suppliers/reports/show', [
            'title' => $result['title'], 'breadcrumbs' => self::CRUMB + ['Reports' => route('suppliers.reports'), $result['title'] => null],
            'key' => $report, 'result' => $result, 'f' => $f, 'rows' => new Paginator(array_slice($result['rows'], ($page - 1) * $per, $per), count($result['rows']), $page, $per),
            'types' => $svc->types(false), 'states' => $svc->states(), 'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(), 'statuses' => SupplierService::STATUSES,
            'treatments' => SupplierService::GST_TREATMENTS, 'canExport' => can('suppliers.export'),
        ]);
    }

    public function export(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::TEXT, self::INTS);
        $format = $request->string('format', 'csv');
        $result = (new SupplierReportService(App::db()))->run($this->user(), $report, $f);
        App::activity()->log('supplier.report_exported', "Exported supplier report \"{$result['title']}\" as {$format} (" . count($result['rows']) . ' rows)', module: 'suppliers',
            newValues: ['report' => $report, 'format' => $format, 'filters' => array_filter($f), 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'suppliers-' . $report);
    }
}
