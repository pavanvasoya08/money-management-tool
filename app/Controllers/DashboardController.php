<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\LoginAttempt;
use App\Models\User;

/**
 * Phase 1 dashboard: real system data only (users, logins, activity).
 * No financial figures are shown until the finance modules exist.
 */
final class DashboardController extends Controller
{
    public function home(Request $request): Response
    {
        return $this->redirect(App::auth()->check() ? route('dashboard') : route('login'));
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $gate = App::gate();
        $userId = (int) $user['id'];
        $canUsers = $gate->allows($user, 'users.view');
        $canLogins = $gate->allows($user, 'users.view_login_history');
        $canActivity = $gate->allows($user, 'activity_logs.view');

        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'breadcrumbs' => ['Dashboard' => null],
            'me' => $user,
            'roles' => User::roles($userId),
            'isSuperAdmin' => $gate->isSuperAdmin($user),
            'canUsers' => $canUsers,
            'canLogins' => $canLogins,
            'canActivity' => $canActivity,
            'counts' => $canUsers ? User::counts() : null,
            'logins' => LoginAttempt::recent(8, $canLogins ? null : $userId),
            'activity' => ActivityLog::recent(8, $canActivity ? null : $userId),
            'chart' => $canLogins ? LoginAttempt::dailyCounts(14) : null,
            'withCharts' => $canLogins,
        ]);
    }
}
