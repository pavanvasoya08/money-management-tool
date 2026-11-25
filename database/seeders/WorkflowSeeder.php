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
