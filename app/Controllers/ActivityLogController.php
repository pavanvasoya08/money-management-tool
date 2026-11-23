<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;

/** Read-only view of the audit trail. There are no edit/delete endpoints. */
final class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = ['q' => mb_substr($request->string('q'), 0, 100), 'action' => $request->string('action')];
        if (!preg_match('/^[a-z_]*$/', $filters['action'])) {
            $filters['action'] = '';
        }
        return $this->view('activity/index', [
            'title' => 'Activity log',
            'breadcrumbs' => ['Activity log' => null],
            'logs' => ActivityLog::paginate($filters, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15)),
            'filters' => $filters,
            'groups' => ActivityLog::actionGroups(),
        ]);
    }
}
