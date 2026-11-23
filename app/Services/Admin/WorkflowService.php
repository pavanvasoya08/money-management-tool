<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Core\Database;
use App\Exceptions\DomainRuleException;
use App\Exceptions\ValidationException;

/** Super Admin configuration of approval workflows and their steps. */
final class WorkflowService extends AdminService
{
    /** @param array<string, mixed> $actor */
    public function update(int $workflowId, bool $active, ?string $description, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $wf = $this->wf($workflowId);
        if ($active && !$this->db->scalar('SELECT 1 FROM approval_workflow_steps WHERE workflow_id = ?', [$workflowId])) {
            throw new DomainRuleException('Add at least one approval step before activating the workflow.');
        }
        $old = ['is_active' => (bool) $wf['is_active'], 'description' => $wf['description']];
        $new = ['is_active' => $active, 'description' => $description];
        if ($old === $new) {
            return;
        }
        $this->db->update('approval_workflows', ['is_active' => $active ? 1 : 0, 'description' => $description, 'updated_by' => (int) $actor['id'], 'updated_at' => now()], ['id' => $workflowId]);
        $this->audit('workflow.updated', "Updated approval workflow {$wf['name']}" . ($active !== (bool) $wf['is_active'] ? ($active ? ' (activated)' : ' (deactivated)') : ''), 'approval_workflow', $workflowId, $old, $new);
    }

    /**
     * Replace the steps of a workflow. Blocked while requests are pending so
     * in-flight approvals keep the rules they were submitted under.
     *
     * @param list<array{name: string, approver_type: string, approver_role_id?: ?int, approver_user_id?: ?int, min_amount?: float|string}> $steps
     * @param array<string, mixed> $actor
     */
    public function saveSteps(int $workflowId, array $steps, array $actor): void
    {
        $this->requireSuperAdmin($actor);
        $wf = $this->wf($workflowId);
        if ($this->db->scalar("SELECT 1 FROM approval_requests WHERE workflow_id = ? AND status = 'pending'", [$workflowId])) {
            throw new DomainRuleException('Steps cannot be changed while requests are pending in this workflow.');
        }
        $clean = [];
        foreach (array_values($steps) as $i => $s) {
            $name = trim((string) ($s['name'] ?? ''));
            $type = (string) ($s['approver_type'] ?? '');
            $min = (string) ($s['min_amount'] ?? '0');
            if ($name === '' && $type === '') {
                continue; // empty row in the form
            }
            if ($name === '' || !in_array($type, ['role', 'user', 'permission'], true) || !is_numeric($min) || (float) $min < 0) {
                throw ValidationException::withMessage('steps', 'Step ' . ($i + 1) . ': name, approver type and a non-negative minimum amount are required.');
            }
            $roleId = $type === 'role' ? (int) ($s['approver_role_id'] ?? 0) : null;
            $userId = $type === 'user' ? (int) ($s['approver_user_id'] ?? 0) : null;
            if ($type === 'role' && !$this->db->scalar('SELECT 1 FROM roles WHERE id = ? AND is_active = 1', [$roleId])) {
                throw ValidationException::withMessage('steps', 'Step ' . ($i + 1) . ': choose an active approver role.');
            }
            if ($type === 'user' && !$this->db->scalar('SELECT 1 FROM users WHERE id = ? AND deleted_at IS NULL', [$userId])) {
                throw ValidationException::withMessage('steps', 'Step ' . ($i + 1) . ': choose an approver user.');
            }
            $clean[] = ['name' => mb_substr($name, 0, 120), 'approver_type' => $type, 'approver_role_id' => $roleId, 'approver_user_id' => $userId, 'min_amount' => number_format((float) $min, 2, '.', '')];
        }
        $old = array_map(static fn (array $s): array => array_intersect_key($s, array_flip(['step_order', 'name', 'approver_type', 'approver_role_id', 'approver_user_id', 'min_amount'])),
            $this->db->select('SELECT * FROM approval_workflow_steps WHERE workflow_id = ? ORDER BY step_order', [$workflowId]));
        $this->db->transaction(function (Database $db) use ($workflowId, $clean, $wf): void {
            $db->delete('approval_workflow_steps', ['workflow_id' => $workflowId]);
            foreach ($clean as $i => $s) {
                $db->insert('approval_workflow_steps', ['workflow_id' => $workflowId, 'step_order' => $i + 1] + $s + ['created_at' => now(), 'updated_at' => now()]);
            }
            if ($clean === [] && $wf['is_active']) {
                $db->update('approval_workflows', ['is_active' => 0, 'updated_at' => now()], ['id' => $workflowId]);
            }
        });
        $this->audit('workflow.steps_changed', "Changed steps of {$wf['name']} (" . count($clean) . ' step(s))', 'approval_workflow', $workflowId, ['steps' => $old], ['steps' => $clean]);
    }

    /** @return array<string, mixed> */
    private function wf(int $id): array
    {
        return $this->db->first('SELECT * FROM approval_workflows WHERE id = ?', [$id]) ?? throw new DomainRuleException('Workflow not found.');
    }
}
