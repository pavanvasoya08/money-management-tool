<?php

declare(strict_types=1);

namespace App\Controllers\Product;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Services\Export\TableExport;
use App\Services\Product\PricingService;
use App\Services\Product\ProductReportService;
use App\Services\Product\ProductService;

/** The ten product reports with filters, pagination and CSV / Excel / PDF export. */
final class ProductReportController extends ProductBaseController
{
    private const TEXT = ['q', 'status', 'hsn', 'archived', 'as_of', 'price_type', 'mapping'];
    private const INTS = ['type', 'category', 'brand', 'gst', 'activity', 'supplier'];

    public function index(Request $request): Response
    {
        return $this->view('products/reports/index', ['title' => 'Product reports', 'breadcrumbs' => self::CRUMB + ['Reports' => null], 'reports' => ProductReportService::REPORTS]);
    }

    public function show(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::TEXT, self::INTS);
        $result = (new ProductReportService(App::db()))->run($this->user(), $report, $f);
        $per = (int) setting('records_per_page', 15) * 2;
        $page = max(1, (int) $request->input('page', 1));
        return $this->view('products/reports/show', [
            'title' => $result['title'], 'breadcrumbs' => self::CRUMB + ['Reports' => route('products.reports'), $result['title'] => null],
            'key' => $report, 'result' => $result, 'f' => $f, 'rows' => new Paginator(array_slice($result['rows'], ($page - 1) * $per, $per), count($result['rows']), $page, $per),
            'gsts' => $this->gstOptions(), 'activities' => $this->activityOptions(), 'suppliers' => $this->supplierOptions(), 'statuses' => ProductService::STATUSES,
            'priceTypes' => PricingService::TYPES, 'canExport' => can('products.export'),
        ] + $this->masters());
    }

    public function export(Request $request, string $report): Response
    {
        $f = $this->filters($request, self::TEXT, self::INTS);
        $format = $request->string('format', 'csv');
        $result = (new ProductReportService(App::db()))->run($this->user(), $report, $f);
        App::activity()->log('product.report_exported', "Exported product report \"{$result['title']}\" as {$format} (" . count($result['rows']) . ' rows)', module: 'products',
            newValues: ['report' => $report, 'format' => $format, 'filters' => array_filter($f), 'columns' => array_keys($result['columns'])]);
        return (new TableExport($result['title'], $result['columns'], $result['rows'], $result['meta'], $result['totals']))->response($format, 'products-' . $report);
    }
}
