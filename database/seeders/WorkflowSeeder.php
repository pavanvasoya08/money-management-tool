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
        'bank_transfer' => ['Bank transfer approval', 'bank_accounts', 'Approve transfers between bank accounts.'],
        'partner_withdrawal' => ['Partner withdrawal approval', 'capital_contributions', 'Approve partner capital withdrawals.'],
        'sales_return' => ['Sales return approval', 'returns_refunds', 'Approve returns and refunds.'],
        'journal_entry' => ['Journal entry approval', 'accounting', 'Approve manual journal entries.'],
    ];

    public function run(): void
    {
        $now = now();
        foreach (self::WORKFLOWS as $key => [$name, $module, $description]) {
            if ($this->db->scalar('SELECT 1 FROM approval_workflows WHERE workflow_key = ?', [$key])) {
                continue;
            }
            $moduleId = $this->db->scalar('SELECT id FROM modules WHERE module_key = ?', [$module]);
            if ($moduleId === null) {
                continue;
            }
            $this->db->insert('approval_workflows', [
                'workflow_key' => $key, 'name' => $name, 'description' => $description, 'module_id' => $moduleId,
                'is_active' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
}
