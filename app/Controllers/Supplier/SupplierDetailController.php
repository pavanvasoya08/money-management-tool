<?php

declare(strict_types=1);

namespace App\Controllers\Supplier;

use App\Core\App;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Exceptions\ValidationException;
use App\Services\Product\DatedMappingService;
use App\Services\Product\PricingService;
use App\Services\Product\ProductService;
use App\Services\Product\SupplierMappingService;
use App\Services\Supplier\SupplierBankService;
use App\Services\Supplier\SupplierDetailService;
use App\Services\Supplier\SupplierReportService;
use App\Services\Supplier\SupplierService;
use App\Services\Export\TableExport;
use App\Support\BusinessDate;
use App\Support\Money;

/**
 * Supplier addresses, bank details, documents, GST / activity / product mappings, payment
 * references, the supplier types master and the cross-supplier list pages.
 */
final class SupplierDetailController extends SupplierBaseController
{
    // ---------------------------------------------------------------- addresses

    public function saveAddress(Request $request, string $id, ?string $address = null): Response
    {
        $s = $this->findSupplier($id);
        if (!App::fields()->access($this->user(), 'suppliers', 'addresses')->canEdit()) {
            throw new AuthorizationException('suppliers.manage_addresses', 'Addresses are read-only for you.');
        }
        (new SupplierDetailService(App::db()))->saveAddress($s, $request->all(), $this->user(), $address !== null ? $this->intParam($address) : null);
        return $this->backTo((int) $s['id'], 'addresses', $address !== null ? 'Address updated.' : 'Address added.');
    }

    public function archiveAddress(Request $request, string $id, string $address): Response
    {
        $s = $this->findSupplier($id);
        (new SupplierDetailService(App::db()))->archiveAddress($s, $this->intParam($address), $this->user());
        return $this->backTo((int) $s['id'], 'addresses', 'Address archived.');
    }

    // ---------------------------------------------------------------- bank details

    public function addBank(Request $request, string $id): Response
    {
        $this->authorize('suppliers.view_bank_details');
        $s = $this->findSupplier($id);
        $change = (new SupplierBankService(App::db()))->add($s, $request->all(), $this->user());
        return $this->backTo((int) $s['id'], 'bank', $change['status'] === 'approved' ? 'Bank details added and active.' : 'Bank details sent for approval. They cannot be used until approved.',
            $change['status'] === 'approved' ? 'success' : 'info');
    }

    public function primaryBank(Request $request, string $id, string $detail): Response
    {
        $this->authorize('suppliers.view_bank_details');
        $s = $this->findSupplier($id);
        $svc = new SupplierBankService(App::db());
        $row = $svc->find((int) $s['id'], $this->intParam($detail)) ?? throw new HttpException(404, 'Bank details not found.');
        $change = $svc->requestPrimary($s, $row, $request->string('reason'), $this->user());
        return $this->backTo((int) $s['id'], 'bank', $change['status'] === 'approved' ? 'Primary account changed.' : 'Primary-account change sent for approval.', $change['status'] === 'approved' ? 'success' : 'info');
    }

    public function deactivateBank(Request $request, string $id, string $detail): Response
    {
        $this->authorize('suppliers.view_bank_details');
        $s = $this->findSupplier($id);
        $svc = new SupplierBankService(App::db());
        $row = $svc->find((int) $s['id'], $this->intParam($detail)) ?? throw new HttpException(404, 'Bank details not found.');
        $svc->deactivate($s, $row, $request->string('reason'), $this->user());
        return $this->backTo((int) $s['id'], 'bank', 'Bank details deactivated.');
    }

    /** Full account number on request; permission- and field-rule-checked, and logged. */
    public function revealBank(Request $request, string $id, string $detail): Response
    {
        $s = $this->findSupplier($id);
        $svc = new SupplierBankService(App::db());
        $row = $svc->find((int) $s['id'], $this->intParam($detail)) ?? throw new HttpException(404, 'Bank details not found.');
        $number = $svc->fullNumber($row, $this->user());
        if ($number === null) {
            return Response::json(['error' => 'You may not view the full account number.'], 403);
        }
        App::activity()->log('supplier.account_number_viewed', 'Viewed the full bank account number ' . SupplierBankService::masked($row) . " of supplier {$s['supplier_code']}", 'supplier', (int) $s['id'], module: 'suppliers');
        return Response::json(['number' => $number]);
    }

    // ---------------------------------------------------------------- documents

    public function uploadDocument(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        $file = $request->file('document') ?? throw ValidationException::withMessage('document', 'Choose a file to upload.');
        (new SupplierDetailService(App::db()))->uploadDocument($s, $file, $request->all(), $this->user());
        return $this->backTo((int) $s['id'], 'documents', 'Document uploaded.');
    }

    public function document(Request $request, string $id, string $doc): Response
    {
        $s = $this->findSupplier($id);
        $d = App::db()->first('SELECT * FROM supplier_documents WHERE id = ? AND supplier_id = ?', [$this->intParam($doc), $s['id']]) ?? throw new HttpException(404, 'Document not found.');
        if ($d['category'] === 'bank_proof') {
            $this->authorize('suppliers.view_bank_details');
        }
        return (new SupplierDetailService(App::db()))->documentResponse($d, $s, $request->string('download') !== '1');
    }

    public function archiveDocument(Request $request, string $id, string $doc): Response
    {
        $s = $this->findSupplier($id);
        $d = App::db()->first('SELECT * FROM supplier_documents WHERE id = ? AND supplier_id = ?', [$this->intParam($doc), $s['id']]) ?? throw new HttpException(404, 'Document not found.');
        if ($d['category'] === 'bank_proof') {
            $this->authorize('suppliers.view_bank_details');
        }
        (new SupplierDetailService(App::db()))->archiveDocument($s, $d, $request->string('reason'), $this->user());
        return $this->backTo((int) $s['id'], 'documents', 'Document archived.');
    }

    // ---------------------------------------------------------------- mappings

    public function addGst(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        (new SupplierService(App::db()))->assertWritable($s);
        $gst = (int) $request->input('gst_registration_id', 0);
        if (!in_array($gst, array_map(static fn (array $g): int => (int) $g['id'], $this->gstOptions()), true)) {
            throw ValidationException::withMessage('gst_registration_id', 'Choose a GST registration you can see.');
        }
        (new DatedMappingService(App::db(), 'supplier_gst'))->add($s, $gst, $request->all(), $this->user());
        return $this->backTo((int) $s['id'], 'gst', 'GST registration linked.');
    }

    public function endGst(Request $request, string $id, string $mapping): Response
    {
        $s = $this->findSupplier($id);
        (new DatedMappingService(App::db(), 'supplier_gst'))->end($s, $this->intParam($mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->backTo((int) $s['id'], 'gst', 'Link ended. It stays in the history.');
    }

    public function addActivity(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        (new SupplierService(App::db()))->assertWritable($s);
        (new DatedMappingService(App::db(), 'supplier_activity'))->add($s, (int) $request->input('business_activity_id', 0), $request->all(), $this->user());
        return $this->backTo((int) $s['id'], 'activities', 'Business activity linked.');
    }

    public function endActivity(Request $request, string $id, string $mapping): Response
    {
        $s = $this->findSupplier($id);
        (new DatedMappingService(App::db(), 'supplier_activity'))->end($s, $this->intParam($mapping), $request->string('effective_to') ?: null, $request->string('reason'), $this->user());
        return $this->backTo((int) $s['id'], 'activities', 'Link ended. It stays in the history.');
    }

    /** Link a product from the supplier page (same mapping as on the product page). */
    public function addProduct(Request $request, string $id): Response
    {
        $me = $this->user();
        $s = $this->findSupplier($id);
        $this->authorize('products.view');
        $pid = (int) $request->input('product_id', 0);
        App::dataAccess()->assertCanAccess($me, 'products', $pid);
        $p = (new ProductService(App::db()))->find($pid) ?? throw new HttpException(404, 'Product not found.');
        $input = ['supplier_id' => (int) $s['id']] + $request->all();
        if (trim((string) ($input['purchase_price'] ?? '')) !== '' && !App::fields()->access($me, 'products', 'purchase_cost')->canEdit()) {
            throw new AuthorizationException('products.manage_pricing', 'You may not set purchase prices.');
        }
        [, $price] = (new SupplierMappingService(App::db()))->add($p, $input, $me);
        return $this->backTo((int) $s['id'], 'products', 'Product linked.' . ($price !== null && $price['status'] === 'pending_approval' ? ' The purchase price is waiting for approval.' : ''));
    }

    // ---------------------------------------------------------------- payment references

    public function linkPayment(Request $request, string $id): Response
    {
        $s = $this->findSupplier($id);
        $scope = $this->bankScope() ?? throw new AuthorizationException('bank_accounts.view', 'You cannot see bank transactions.');
        (new SupplierDetailService(App::db()))->linkPayment($s, (int) $request->input('bank_transaction_id', 0), $request->string('reference') ?: null, $request->string('notes') ?: null, $scope, $this->user());
        return $this->backTo((int) $s['id'], 'bank', 'Payment reference added. Nothing changed in the bank ledger.');
    }

    public function unlinkPayment(Request $request, string $id, string $link): Response
    {
        $s = $this->findSupplier($id);
        (new SupplierDetailService(App::db()))->unlinkPayment($s, $this->intParam($link), $request->string('reason'), $this->user());
        return $this->backTo((int) $s['id'], 'bank', 'Payment reference removed.');
    }

    // ---------------------------------------------------------------- supplier types

    public function types(Request $request): Response
    {
        return $this->view('suppliers/types', ['title' => 'Supplier types', 'breadcrumbs' => self::CRUMB + ['Types' => null],
            'types' => (new SupplierService(App::db()))->types(false), 'edit' => (int) $request->input('edit', 0)]);
    }

    public function saveType(Request $request, ?string $id = null): Response
    {
        (new SupplierService(App::db()))->saveType($request->all() + ($id === null ? ['is_active' => 1] : []), $this->user(), $id !== null ? $this->intParam($id) : null);
        return $this->redirectRoute('suppliers.types')->with('success', 'Supplier type saved.');
    }

    // ---------------------------------------------------------------- cross-supplier lists

    public function addressList(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'type'], ['state']);
        $sql = "SELECT a.*, st.name AS state, s.supplier_code, s.name AS supplier_name FROM supplier_addresses a JOIN suppliers s ON s.id = a.supplier_id LEFT JOIN gst_states st ON st.id = a.state_id
            WHERE {$w} AND a.status = 'active'";
        $params = $p;
        if (isset(SupplierDetailService::ADDRESS_TYPES[$f['type']])) {
            $sql .= ' AND a.address_type = ?';
            $params[] = $f['type'];
        }
        if ((int) $f['state'] > 0) {
            $sql .= ' AND a.state_id = ?';
            $params[] = (int) $f['state'];
        }
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(s.name) LIKE ? OR LOWER(a.city) LIKE ? OR a.pin_code = ? OR LOWER(a.line1) LIKE ?)';
            array_push($params, $like, $like, $f['q'], $like);
        }
        return $this->view('suppliers/addresses', ['title' => 'Supplier addresses', 'breadcrumbs' => self::CRUMB + ['Addresses' => null],
            'rows' => App::db()->select($sql . ' ORDER BY s.name, a.address_type LIMIT 500', $params), 'f' => $f, 'states' => (new SupplierService(App::db()))->states(),
            'types' => SupplierDetailService::ADDRESS_TYPES, 'fields' => App::fields()->accessMap($this->user(), 'suppliers')]);
    }

    public function bankList(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'status'], []);
        $sql = "SELECT b.id, b.supplier_id, b.account_holder_name, b.bank_name, b.branch_name, b.account_number_last4, b.ifsc_code, b.swift_code, b.account_type, b.is_primary, b.status, b.created_at,
                s.supplier_code, s.name AS supplier_name FROM supplier_bank_details b JOIN suppliers s ON s.id = b.supplier_id WHERE {$w}";
        $params = $p;
        if (isset(SupplierBankService::STATUSES[$f['status']])) {
            $sql .= ' AND b.status = ?';
            $params[] = $f['status'];
        }
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(s.name) LIKE ? OR LOWER(b.bank_name) LIKE ? OR b.account_number_last4 = ? OR LOWER(b.ifsc_code) LIKE ?)';
            array_push($params, $like, $like, substr($f['q'], -4), $like);
        }
        return $this->view('suppliers/bank', ['title' => 'Supplier bank details', 'breadcrumbs' => self::CRUMB + ['Bank details' => null],
            'rows' => App::db()->select($sql . " ORDER BY CASE b.status WHEN 'pending_approval' THEN 0 WHEN 'active' THEN 1 ELSE 2 END, s.name LIMIT 500", $params), 'f' => $f,
            'statuses' => SupplierBankService::STATUSES, 'fields' => App::fields()->accessMap($this->user(), 'suppliers')]);
    }

    public function documentList(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $f = $this->filters($request, ['q', 'category', 'expiring'], []);
        $sql = "SELECT d.*, s.supplier_code, s.name AS supplier_name, u.name AS uploaded_by_name FROM supplier_documents d JOIN suppliers s ON s.id = d.supplier_id LEFT JOIN users u ON u.id = d.uploaded_by
            WHERE {$w} AND d.status = 'active'" . (can('suppliers.view_bank_details') ? '' : " AND d.category <> 'bank_proof'");
        $params = $p;
        if (isset(SupplierDetailService::DOC_CATEGORIES[$f['category']])) {
            $sql .= ' AND d.category = ?';
            $params[] = $f['category'];
        }
        if ($f['expiring'] === '1') {
            $sql .= ' AND d.expiry_date IS NOT NULL AND d.expiry_date <= ?';
            $params[] = BusinessDate::addDays(BusinessDate::today(), 60);
        }
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(d.title) LIKE ? OR LOWER(d.original_name) LIKE ? OR LOWER(s.name) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        return $this->view('suppliers/documents', ['title' => 'Supplier documents', 'breadcrumbs' => self::CRUMB + ['Documents' => null],
            'rows' => App::db()->select($sql . ' ORDER BY d.created_at DESC LIMIT 300', $params), 'f' => $f, 'categories' => SupplierDetailService::DOC_CATEGORIES]);
    }

    public function mappingList(Request $request): Response
    {
        $me = $this->user();
        [$w, $p] = $this->scope();
        [$pw, $pp] = can('products.view') ? App::dataAccess()->constrain($me, 'products', 'p') : ['1 = 0', []];
        $f = $this->filters($request, ['q', 'status'], []);
        $sql = "SELECT m.*, s.supplier_code, s.name AS supplier_name, p.product_code, p.name AS product_name, v.name AS variant_name FROM product_supplier_mappings m JOIN suppliers s ON s.id = m.supplier_id
            JOIN products p ON p.id = m.product_id LEFT JOIN product_variants v ON v.id = m.variant_id WHERE {$w} AND {$pw}" . ($f['status'] === 'all' ? '' : " AND m.status <> 'ended'");
        $params = array_merge($p, $pp);
        if ($f['q'] !== '') {
            $like = '%' . mb_strtolower($f['q']) . '%';
            $sql .= ' AND (LOWER(s.name) LIKE ? OR LOWER(p.name) LIKE ? OR LOWER(m.supplier_sku) LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $page = max(1, (int) $request->input('page', 1));
        $total = (int) App::db()->scalar("SELECT COUNT(*) FROM ({$sql}) x", $params);
        $rows = App::db()->select($sql . ' ORDER BY s.name, p.name LIMIT 40 OFFSET ' . (($page - 1) * 40), $params);
        $pricing = new PricingService(App::db());
        foreach ($rows as &$r) {
            $price = $pricing->priceOn((int) $r['product_id'], $r['variant_id'] !== null ? (int) $r['variant_id'] : null, 'supplier_purchase', BusinessDate::today(), (int) $r['supplier_id']);
            $r['purchase_price'] = $price !== null ? Money::fromDb($price['amount']) : null;
        }
        return $this->view('suppliers/mappings', ['title' => 'Supplier product mappings', 'breadcrumbs' => self::CRUMB + ['Product mappings' => null], 'rows' => new Paginator($rows, $total, $page, 40),
            'f' => $f, 'productFields' => App::fields()->accessMap($me, 'products')]);
    }

    public function historyList(Request $request): Response
    {
        [$w, $p] = $this->scope();
        $page = max(1, (int) $request->input('page', 1));
        $sql = "SELECT h.*, s.supplier_code, s.name AS supplier_name, u.name AS actor_name FROM supplier_history h JOIN suppliers s ON s.id = h.supplier_id LEFT JOIN users u ON u.id = h.actor_id WHERE {$w}";
        $total = (int) App::db()->scalar("SELECT COUNT(*) FROM ({$sql}) x", $p);
        $rows = App::db()->select($sql . ' ORDER BY h.id DESC LIMIT 40 OFFSET ' . (($page - 1) * 40), $p);
        foreach ($rows as &$r) {
            $r['old_values'] = $r['old_values'] !== null ? json_decode((string) $r['old_values'], true) : null;
            $r['new_values'] = $r['new_values'] !== null ? json_decode((string) $r['new_values'], true) : null;
        }
        return $this->view('suppliers/history', ['title' => 'Supplier history', 'breadcrumbs' => self::CRUMB + ['History' => null], 'rows' => new Paginator($rows, $total, $page, 40)]);
    }
}
