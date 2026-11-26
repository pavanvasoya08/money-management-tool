<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Seeder;

/**
 * Registers the approval workflow types (configuration only — inactive and
 * without steps until a Super Admin configures them). No transactions are created.
 */
final class WorkflowSeeder extends Seeder
{
    public const WORKFLOWS = [
        'expense' => ['Expense approval', 'expenses', 'Approve expenses before they are paid.'],
        'purchase' => ['Purchase approval', 'purchases', 'Approve purchase orders and supplier bills.'],
        'bank_transfer' => ['Internal bank transfer approval', 'bank_accounts', 'Approve internal transfers between bank accounts before both sides are posted.', ['approve_transfers', 'reject_transfers']],
        'partner_withdrawal' => ['Partner withdrawal approval', 'capital_withdrawals', 'Approve partner capital withdrawals before they can be posted.'],
        'capital_contribution' => ['Capital contribution approval', 'capital_contributions', 'Approve partner capital contributions before they can be posted.'],
        'capital_adjustment' => ['Capital adjustment approval', 'capital_adjustments', 'Approve opening balances, corrections and reclassifications of partner capital.'],
        'sales_return' => ['Sales return approval', 'returns_refunds', 'Approve returns and refunds.'],
        'journal_entry' => ['Journal entry approval', 'accounting', 'Approve manual journal entries.'],
        // Phase 4 — GST registration master data.
        'gst_registration_create' => ['New GST registration approval', 'gst_registrations', 'Approve new GST registrations before they become usable.'],
        'gst_registration_update' => ['GST key-detail change approval', 'gst_registrations', 'Approve changes to key details: GSTIN, legal name, PAN, registration type, registration and effective dates.'],
        'gst_registration_cancel' => ['GST cancellation approval', 'gst_registrations', 'Approve marking a GST registration as cancelled.'],
        // Phase 5 — bank accounts. The 4th element names the permission actions approvers / rejecters need.
        'bank_account_create' => ['New bank account approval', 'bank_accounts', 'Approve new bank accounts before they become active.'],
        'bank_account_update' => ['Bank account key-detail change approval', 'bank_accounts', 'Approve changes to account number, IFSC, holder, bank, type and overdraft settings, and central-account changes.'],
        'bank_account_close' => ['Bank account closure approval', 'bank_accounts', 'Approve closing a bank account.'],
        'bank_deposit' => ['Bank deposit approval', 'bank_accounts', 'Approve deposits and interest entries before they are posted.', ['approve_transactions', 'reject_transactions']],
        'bank_withdrawal' => ['Bank withdrawal approval', 'bank_accounts', 'Approve withdrawals and bank charges before they are posted.', ['approve_transactions', 'reject_transactions']],
        'bank_adjustment' => ['Bank adjustment approval', 'bank_accounts', 'Approve other bank adjustments and opening-balance adjustments before they are posted.', ['approve_transactions', 'reject_transactions']],
        'bank_reconciliation_reopen' => ['Bank reconciliation reopening approval', 'bank_accounts', 'Approve reopening a completed bank reconciliation.'],
        // Phase 6 — products and suppliers. All inactive until a Super Admin adds steps and activates them.
        'product_create' => ['New product approval', 'products', 'Approve new products before they become active.'],
        'product_update' => ['Product key-detail change approval', 'products', 'Approve changes to name, type, category, brand, unit, barcode and inventory tracking of active products.'],
        'product_sku_change' => ['SKU change approval', 'products', 'Approve changing the SKU of a product or variant.'],
        'product_tax_change' => ['GST / HSN change approval', 'products', 'Approve new or changed GST rates, HSN/SAC codes and tax categories before they take effect.'],
        'product_price_change' => ['Price change approval', 'products', 'Approve new prices (standard, selling, wholesale, marketplace and supplier purchase prices) before they take effect.'],
        'product_archive' => ['Product archival approval', 'products', 'Approve archiving a product.'],
        'product_preferred_supplier' => ['Preferred supplier change approval', 'products', 'Approve changing the preferred supplier of a product or variant.'],
        'supplier_create' => ['New supplier approval', 'suppliers', 'Approve new suppliers before they become active.'],
        'supplier_gstin_change' => ['Supplier GSTIN change approval', 'suppliers', 'Approve changes to a supplier\'s GSTIN, PAN or GST treatment.'],
        'supplier_bank_change' => ['Supplier bank detail approval', 'suppliers', 'Approve new or changed supplier bank details before they can be used.'],
        'supplier_archive' => ['Supplier archival approval', 'suppliers', 'Approve archiving a supplier.'],
        // Phase 7 — inventory. Nothing is posted to the stock ledger until the request is approved.
        'inventory_opening_stock' => ['Opening stock approval', 'inventory', 'Approve opening stock before it is posted to the stock ledger.', ['approve_opening_stock', 'approve_opening_stock']],
        'inventory_inward' => ['Stock inward approval', 'inventory', 'Approve purchase receipts, customer returns and other stock receipts before they are posted.'],
        'inventory_outward' => ['Stock outward approval', 'inventory', 'Approve dispatches, supplier returns, write-offs and consumption before they are posted.'],
        'inventory_transfer' => ['Stock transfer approval', 'inventory', 'Approve transfers between warehouses or locations before stock is dispatched.', ['approve_stock_transfers', 'approve_stock_transfers']],
        'inventory_adjustment' => ['Stock adjustment approval', 'inventory', 'Approve stock counts, condition changes and other adjustments before they are posted.', ['approve_stock_adjustments', 'approve_stock_adjustments']],
        'inventory_batch_correction' => ['Batch correction approval', 'inventory', 'Approve corrections to batch numbers, manufacturing and expiry dates of batches that hold or held stock.'],
        'inventory_serial_correction' => ['Serial number correction approval', 'inventory', 'Approve corrections to registered serial numbers.'],
        'inventory_reservation_override' => ['Reserved stock override approval', 'inventory', 'Approve issuing stock that is reserved for another reference.'],
        'inventory_negative_override' => ['Negative stock override approval', 'inventory', 'Approve issuing more stock than is on hand.'],
        'inventory_valuation_correction' => ['Inventory valuation correction approval', 'inventory', 'Approve revaluing the unit cost of stock on hand.'],
    ];

    public function run(): void
    {
        $now = now();
        foreach (self::WORKFLOWS as $key => $def) {
            [$name, $module, $description] = $def;
            [$approveAction, $rejectAction] = $def[3] ?? [null, null];
            $moduleId = $this->db->scalar('SELECT id FROM modules WHERE module_key = ?', [$module]);
            if ($moduleId === null) {
                continue;
            }
            $existing = $this->db->first('SELECT id, module_id FROM approval_workflows WHERE workflow_key = ?', [$key]);
            if ($existing !== null) {
                // Phase 3 moved withdrawals to their own module; re-point only while no request has used the workflow.
                if ((int) $existing['module_id'] !== (int) $moduleId && !$this->db->scalar('SELECT 1 FROM approval_requests WHERE workflow_id = ?', [$existing['id']])) {
                    $this->db->update('approval_workflows', ['module_id' => $moduleId, 'description' => $description, 'updated_at' => $now], ['id' => $existing['id']]);
                }
                if ($approveAction !== null) {
                    // Phase 5 adds per-workflow approver permissions; fill them in only where not yet set.
                    $this->db->execute('UPDATE approval_workflows SET approve_action = COALESCE(approve_action, ?), reject_action = COALESCE(reject_action, ?), name = ?, description = ? WHERE id = ? AND approve_action IS NULL',
                        [$approveAction, $rejectAction, $name, $description, $existing['id']]);
                }
                continue;
            }
            $this->db->insert('approval_workflows', [
                'workflow_key' => $key, 'name' => $name, 'description' => $description, 'module_id' => $moduleId,
                'is_active' => 0, 'approve_action' => $approveAction, 'reject_action' => $rejectAction, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
