<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;

/** Approval inbox: requests the signed-in user may act on, plus their own submissions. */
final class ApprovalController extends Controller
{
    public function index(Request $request): Response
    {
        $me = $this->user();
        return $this->view('approvals/index', [
            'title' => 'Approvals',
            'breadcrumbs' => ['Approvals' => null],
            'inbox' => App::approvals()->pendingFor($me),
            'mine' => App::db()->select(
                'SELECT r.*, w.name AS workflow_name FROM approval_requests r JOIN approval_workflows w ON w.id = r.workflow_id
                 WHERE r.requested_by = ? ORDER BY r.submitted_at DESC LIMIT 50',
                [(int) $me['id']]
            ),
        ]);
    }

    public function show(Request $request, string $id): Response
    {
        $me = $this->user();
        $req = App::approvals()->request($this->intParam($id));
        $canSee = App::gate()->isSuperAdmin($me) || (int) $req['requested_by'] === (int) $me['id']
            || App::gate()->allows($me, $req['module_key'] . '.approve') || App::gate()->allows($me, $req['module_key'] . '.reject');
        if (!$canSee) {
            throw new HttpException(404, 'Request not found.');
        }
        return $this->view('approvals/show', [
            'title' => 'Approval request #' . $req['id'],
            'breadcrumbs' => ['Approvals' => route('approvals.index'), '#' . $req['id'] => null],
            'req' => $req,
            'history' => App::approvals()->history((int) $req['id']),
            'steps' => App::approvals()->steps((int) $req['workflow_id']),
            'required' => json_decode((string) $req['required_steps'], true) ?: [],
            'canApprove' => App::approvals()->whyNot($me, $req, 'approve') === null,
            'canReject' => App::approvals()->whyNot($me, $req, 'reject') === null,
            'whyNot' => App::approvals()->whyNot($me, $req, 'approve'),
            'isRequester' => (int) $req['requested_by'] === (int) $me['id'],
        ]);
    }

    public function approve(Request $request, string $id): Response
    {
        $data = $this->validate($request, ['comment' => 'nullable|string|max:500']);
        $req = App::approvals()->approve($this->intParam($id), $this->user(), $data['comment']);
        return $this->redirectRoute('approvals.show', ['id' => $req['id']])->with('success', $req['status'] === 'approved' ? 'Request fully approved.' : 'Step approved — sent to the next approver.');
    }

    public function reject(Request $request, string $id): Response
    {
        $data = $this->validate($request, ['reason' => 'required|string|min:3|max:500'], ['reason' => 'Rejection reason']);
        App::approvals()->reject($this->intParam($id), $this->user(), (string) $data['reason']);
        return $this->redirectRoute('approvals.show', ['id' => $id])->with('success', 'Request rejected.');
    }

    public function cancel(Request $request, string $id): Response
    {
        App::approvals()->cancel($this->intParam($id), $this->user());
        return $this->redirectRoute('approvals.show', ['id' => $id])->with('success', 'Request cancelled.');
    }
}
