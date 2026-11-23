<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Admin\WorkflowService;

final class WorkflowController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->admin('admin/workflows/index', 'workflows', 'Approval workflows', [
            'workflows' => App::db()->select(
                "SELECT w.*, m.name AS module_name, m.module_key,
                        (SELECT COUNT(*) FROM approval_workflow_steps s WHERE s.workflow_id = w.id) AS step_count,
                        (SELECT COUNT(*) FROM approval_requests r WHERE r.workflow_id = w.id AND r.status = 'pending') AS pending
                 FROM approval_workflows w JOIN modules m ON m.id = w.module_id ORDER BY w.name"
            ),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $wf = App::db()->first('SELECT w.*, m.name AS module_name, m.module_key FROM approval_workflows w JOIN modules m ON m.id = w.module_id WHERE w.id = ?', [$this->intParam($id)])
            ?? throw new HttpException(404, 'Workflow not found.');
        return $this->view('admin/workflows/show', [
            'title' => $wf['name'], 'section' => 'workflows',
            'breadcrumbs' => ['Control Center' => route('admin.overview'), 'Approval workflows' => route('admin.workflows'), $wf['name'] => null],
            'wf' => $wf,
            'steps' => App::approvals()->steps((int) $wf['id']),
            'roles' => App::db()->select('SELECT id, name FROM roles WHERE is_active = 1 ORDER BY name'),
            'users' => App::db()->select('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name'),
            'pending' => (int) App::db()->scalar("SELECT COUNT(*) FROM approval_requests WHERE workflow_id = ? AND status = 'pending'", [$wf['id']]),
            'history' => App::db()->select('SELECT a.*, u.name AS user_name FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.subject_type = ? AND a.subject_id = ? ORDER BY a.id DESC LIMIT 20', ['approval_workflow', $wf['id']]),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $data = $this->validate($request, ['is_active' => 'nullable|boolean', 'description' => 'nullable|string|max:255']);
        (new WorkflowService())->update($this->intParam($id), (bool) $data['is_active'], $data['description'], $this->user());
        return $this->redirect(route('admin.workflows.show', ['id' => $id]))->with('success', 'Workflow saved.');
    }

    public function steps(Request $request, string $id): Response
    {
        $steps = array_values(array_filter((array) $request->input('steps', []), 'is_array'));
        (new WorkflowService())->saveSteps($this->intParam($id), $steps, $this->user());
        return $this->redirect(route('admin.workflows.show', ['id' => $id]))->with('success', 'Approval steps saved.');
    }

    public function requests(Request $request): Response
    {
        $status = in_array($request->string('status'), ['pending', 'approved', 'rejected', 'cancelled'], true) ? $request->string('status') : '';
        return $this->admin('admin/workflows/requests', 'workflows', 'Approval requests', [
            'status' => $status,
            'requests' => App::db()->select(
                'SELECT r.*, w.name AS workflow_name, u.name AS requester_name FROM approval_requests r JOIN approval_workflows w ON w.id = r.workflow_id
                 JOIN users u ON u.id = r.requested_by' . ($status !== '' ? ' WHERE r.status = ?' : '') . ' ORDER BY r.submitted_at DESC LIMIT 200',
                $status !== '' ? [$status] : []
            ),
        ]);
    }
}
