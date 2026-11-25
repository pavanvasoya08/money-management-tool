<?php

declare(strict_types=1);

namespace App\Services\Approval;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\AuthorizationException;
use App\Exceptions\DomainRuleException;

/**
 * Configurable, sequential, amount-aware approval engine for every future
 * financial module (expenses, purchases, bank transfers, withdrawals, returns,
 * journal entries, …).
 *
 * Integration from a module (Phase 3+):
 *   $request = App::approvals()->submit('expense', 'expense', $expenseId, $amount, "Expense #{$no}", $user);
 *   if ($request['status'] === 'approved') { ... }          // workflow inactive or no step applies
 *   ApprovalEngine::onDecision('expense', function (array $request, string $status): void { ... });
 *
 * Rules:
 *  - Submitting needs "<module>.create".
 *  - A step applies when the amount is >= its min_amount; applicable steps run in order.
 *  - To act on the current step a user needs "<module>.approve" (or ".reject" to reject)
 *    AND must match the step's approver (role, specific user, or any holder of the permission).
 *  - With "Block approving your own requests" on, requesters cannot act on their own requests.
 *  - Rejection requires a reason. History is append-only.
 *  - Inactive workflow or no applicable step → approved immediately (recorded in history).
 */
final class ApprovalEngine
{
    /** @var array<string, list<callable(array<string, mixed>, string): void>> */
    private static array $listeners = [];

    public function __construct(private readonly Database $db)
    {
    }

    /** @param callable(array<string, mixed> $request, string $status): void $listener */
    public static function onDecision(string $subjectType, callable $listener): void
    {
        self::$listeners[$subjectType][] = $listener;
    }

    /** @return array<string, mixed> */
    public function workflow(string $key): array
    {
        return $this->db->first('SELECT w.*, m.module_key FROM approval_workflows w JOIN modules m ON m.id = w.module_id WHERE w.workflow_key = ?', [$key])
            ?? throw new DomainRuleException("Unknown approval workflow [{$key}].");
    }

    /** @return list<array<string, mixed>> */
    public function steps(int $workflowId): array
    {
        return $this->db->select(
            'SELECT s.*, r.name AS role_name, u.name AS user_name FROM approval_workflow_steps s
             LEFT JOIN roles r ON r.id = s.approver_role_id LEFT JOIN users u ON u.id = s.approver_user_id
             WHERE s.workflow_id = ? ORDER BY s.step_order',
            [$workflowId]
        );
    }

    /** Which steps apply to an amount (ordered). @return list<int> step orders */
    public function requiredSteps(int $workflowId, float $amount): array
    {
        $orders = [];
        foreach ($this->steps($workflowId) as $s) {
            if ($amount + 0.000001 >= (float) $s['min_amount']) {
                $orders[] = (int) $s['step_order'];
            }
        }
        return $orders;
    }

    /**
     * @param array<string, mixed> $user
     * @param string|null $permission permission that entitles the user to submit (default "<module>.create");
     *                                e.g. an edit or cancellation request is submitted with "<module>.edit" / "<module>.cancel"
     * @return array<string, mixed> the request
     */
    public function submit(string $workflowKey, string $subjectType, int $subjectId, float $amount, string $title, array $user, ?string $permission = null): array
    {
        $wf = $this->workflow($workflowKey);
        $permission ??= $wf['module_key'] . '.create';
        if (!str_starts_with($permission, $wf['module_key'] . '.') || !App::gate()->allows($user, $permission)) {
            throw new AuthorizationException($permission, 'You are not allowed to submit requests for this workflow.');
        }
        if ($amount < 0) {
            throw new DomainRuleException('Amount cannot be negative.');
        }
        if ($this->db->scalar("SELECT 1 FROM approval_requests WHERE subject_type = ? AND subject_id = ? AND status = 'pending'", [$subjectType, $subjectId])) {
            throw new DomainRuleException('This record already has a pending approval request.');
        }
        $required = $wf['is_active'] ? $this->requiredSteps((int) $wf['id'], $amount) : [];
        $now = now();
        $id = $this->db->transaction(function (Database $db) use ($wf, $subjectType, $subjectId, $amount, $title, $user, $required, $now): int {
            $id = $db->insert('approval_requests', [
                'workflow_id' => $wf['id'], 'subject_type' => $subjectType, 'subject_id' => $subjectId,
                'title' => mb_substr($title, 0, 200), 'amount' => number_format($amount, 2, '.', ''),
                'requested_by' => (int) $user['id'], 'status' => $required === [] ? 'approved' : 'pending',
                'current_step' => $required[0] ?? null, 'required_steps' => json_encode($required),
                'submitted_at' => $now, 'completed_at' => $required === [] ? $now : null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('approval_actions', ['request_id' => $id, 'step_order' => null, 'action' => 'submitted', 'acted_by' => (int) $user['id'], 'comment' => null, 'created_at' => $now]);
            if ($required === []) {
                $db->insert('approval_actions', ['request_id' => $id, 'step_order' => null, 'action' => 'approved', 'acted_by' => (int) $user['id'],
                    'comment' => $wf['is_active'] ? 'Auto-approved: no approval step applies to this amount' : 'Auto-approved: workflow is inactive', 'created_at' => $now]);
            }
            return $id;
        });
        App::activity()->log('approval.submitted', "Submitted for approval: {$title}", 'approval_request', $id, module: $wf['module_key'], newValues: ['workflow' => $workflowKey, 'amount' => $amount, 'steps' => $required]);
        $request = $this->request($id);
        if ($request['status'] === 'approved') {
            $this->notify($request);
        }
        return $request;
    }

    /** @param array<string, mixed> $user */
    public function approve(int $requestId, array $user, ?string $comment = null): array
    {
        return $this->act($requestId, $user, 'approve', $comment);
    }

    /** @param array<string, mixed> $user */
    public function reject(int $requestId, array $user, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw new DomainRuleException('A rejection reason is required.');
        }
        return $this->act($requestId, $user, 'reject', trim($reason));
    }

    /** Requester withdraws a pending request. @param array<string, mixed> $user */
    public function cancel(int $requestId, array $user): array
    {
        $req = $this->request($requestId);
        if ((int) $req['requested_by'] !== (int) $user['id'] || $req['status'] !== 'pending') {
            throw new DomainRuleException('Only the requester can cancel a pending request.');
        }
        $this->db->transaction(function (Database $db) use ($requestId, $user): void {
            $db->execute("UPDATE approval_requests SET status = 'cancelled', completed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'", [now(), now(), $requestId]);
            $db->insert('approval_actions', ['request_id' => $requestId, 'step_order' => null, 'action' => 'cancelled', 'acted_by' => (int) $user['id'], 'comment' => null, 'created_at' => now()]);
        });
        $req = $this->request($requestId);
        $this->notify($req);
        return $req;
    }

    /**
     * Can the user act on the request's current step? Returns null if yes, otherwise the reason.
     * @param array<string, mixed> $user @param array<string, mixed> $request
     */
    public function whyNot(array $user, array $request, string $action = 'approve'): ?string
    {
        if ($request['status'] !== 'pending') {
            return 'Request is not pending.';
        }
        if ((int) $request['requested_by'] === (int) $user['id'] && (bool) App::settings()->get('block_self_approval', true)) {
            return 'You cannot act on your own request.';
        }
        // A workflow may name its own approver permission (e.g. bank_accounts.approve_transfers).
        $permission = $request['module_key'] . '.' . ($action === 'reject' ? ($request['reject_action'] ?? null ?: 'reject') : ($request['approve_action'] ?? null ?: 'approve'));
        if (!App::gate()->allows($user, $permission)) {
            return "Requires permission {$permission}.";
        }
        $step = $this->db->first('SELECT * FROM approval_workflow_steps WHERE workflow_id = ? AND step_order = ?', [$request['workflow_id'], $request['current_step']]);
        if ($step === null) {
            return 'The current step no longer exists.';
        }
        if (App::gate()->isSuperAdmin($user)) {
            return null;
        }
        return match ($step['approver_type']) {
            'user' => (int) $step['approver_user_id'] === (int) $user['id'] ? null : 'Assigned to a specific approver.',
            'role' => $this->db->scalar('SELECT 1 FROM user_roles WHERE user_id = ? AND role_id = ?', [$user['id'], $step['approver_role_id']]) ? null : 'Requires the approver role for this step.',
            default => null, // 'permission': any holder of <module>.approve
        };
    }

    /** @param array<string, mixed> $user */
    private function act(int $requestId, array $user, string $action, ?string $comment): array
    {
        $req = $this->request($requestId);
        $why = $this->whyNot($user, $req, $action);
        if ($why !== null) {
            throw new AuthorizationException($req['module_key'] . '.' . $action, $why);
        }
        $required = json_decode((string) $req['required_steps'], true) ?: [];
        $pos = array_search((int) $req['current_step'], $required, true);
        $next = $action === 'approve' ? ($required[$pos + 1] ?? null) : null;
        $status = $action === 'reject' ? 'rejected' : ($next === null ? 'approved' : 'pending');

        $this->db->transaction(function (Database $db) use ($req, $user, $action, $comment, $next, $status): void {
            // Optimistic lock: only succeeds if nobody else acted on this step meanwhile.
            $changed = $db->execute(
                "UPDATE approval_requests SET status = ?, current_step = ?, completed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending' AND current_step = ?",
                [$status, $next, $status === 'pending' ? null : now(), now(), $req['id'], $req['current_step']]
            );
            if ($changed !== 1) {
                throw new DomainRuleException('This request was updated by someone else. Please refresh.');
            }
            $db->insert('approval_actions', [
                'request_id' => $req['id'], 'step_order' => (int) $req['current_step'], 'action' => $action === 'reject' ? 'rejected' : 'approved',
                'acted_by' => (int) $user['id'], 'comment' => $comment !== null ? mb_substr($comment, 0, 500) : null, 'created_at' => now(),
            ]);
        });
        App::activity()->log('approval.' . ($action === 'reject' ? 'rejected' : 'approved'), ucfirst($action === 'reject' ? 'rejected' : 'approved') . " step {$req['current_step']} of: {$req['title']}", 'approval_request', (int) $req['id'],
            module: $req['module_key'], oldValues: ['status' => 'pending', 'step' => (int) $req['current_step']], newValues: ['status' => $status, 'next_step' => $next, 'comment' => $comment]);
        $fresh = $this->request((int) $req['id']);
        if ($fresh['status'] !== 'pending') {
            $this->notify($fresh);
        }
        return $fresh;
    }

    /** @return array<string, mixed> */
    public function request(int $id): array
    {
        return $this->db->first(
            'SELECT r.*, w.workflow_key, w.name AS workflow_name, w.approve_action, w.reject_action, m.module_key, u.name AS requester_name FROM approval_requests r
             JOIN approval_workflows w ON w.id = r.workflow_id JOIN modules m ON m.id = w.module_id JOIN users u ON u.id = r.requested_by WHERE r.id = ?',
            [$id]
        ) ?? throw new DomainRuleException('Approval request not found.');
    }

    /** @return list<array<string, mixed>> */
    public function history(int $requestId): array
    {
        return $this->db->select('SELECT a.*, u.name AS user_name FROM approval_actions a JOIN users u ON u.id = a.acted_by WHERE a.request_id = ? ORDER BY a.id', [$requestId]);
    }

    /** Pending requests whose current step this user can act on. @param array<string, mixed> $user @return list<array<string, mixed>> */
    public function pendingFor(array $user, int $limit = 100): array
    {
        $rows = $this->db->select(
            "SELECT r.*, w.workflow_key, w.name AS workflow_name, w.approve_action, w.reject_action, m.module_key, u.name AS requester_name FROM approval_requests r
             JOIN approval_workflows w ON w.id = r.workflow_id JOIN modules m ON m.id = w.module_id JOIN users u ON u.id = r.requested_by
             WHERE r.status = 'pending' ORDER BY r.submitted_at LIMIT " . max(1, min(500, $limit))
        );
        return array_values(array_filter($rows, fn (array $r): bool => $this->whyNot($user, $r) === null));
    }

    /** @param array<string, mixed> $request */
    private function notify(array $request): void
    {
        foreach (self::$listeners[$request['subject_type']] ?? [] as $listener) {
            $listener($request, (string) $request['status']);
        }
    }
}
