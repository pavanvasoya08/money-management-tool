<?php

declare(strict_types=1);

use App\Controllers\AccessRequestController;
use App\Controllers\AccountController;
use App\Controllers\Admin\AccessController;
use App\Controllers\Admin\AdminUserController;
use App\Controllers\Admin\ControlCenterController;
use App\Controllers\Admin\RoleController;
use App\Controllers\Admin\TemplateController;
use App\Controllers\Admin\WorkflowController;
use App\Controllers\Api\UserApiController;
use App\Controllers\ApprovalController;
use App\Controllers\ActivityLogController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\PasswordResetController;
use App\Controllers\SettingsController;
use App\Controllers\UserController;
use App\Core\Router;

/*
 * Every route except the guest pages requires authentication. Sensitive
 * routes add a permission check ('can:<permission>') that is enforced by the
 * central Gate — controllers never compare role names.
 */
return static function (Router $r): void {
    $r->get('/', [DashboardController::class, 'home'], 'home');

    // ---------- Guests only ----------
    $r->group(['middleware' => ['guest']], static function (Router $r): void {
        $r->get('/login', [AuthController::class, 'showLogin'], 'login');
        $r->post('/login', [AuthController::class, 'login'], 'login.attempt');
        $r->get('/forgot-password', [PasswordResetController::class, 'showRequest'], 'password.request');
        $r->post('/forgot-password', [PasswordResetController::class, 'sendLink'], 'password.email');
        $r->get('/reset-password/{token}', [PasswordResetController::class, 'showReset'], 'password.reset');
        $r->post('/reset-password', [PasswordResetController::class, 'reset'], 'password.update');
    });

    // ---------- Authenticated ----------
    $r->group(['middleware' => ['auth']], static function (Router $r): void {
        $r->post('/logout', [AuthController::class, 'logout'], 'logout');
        $r->get('/dashboard', [DashboardController::class, 'index'], 'dashboard');

        $r->get('/account', [AccountController::class, 'profile'], 'account.profile');
        $r->get('/account/password', [AccountController::class, 'showPassword'], 'account.password');
        $r->post('/account/password', [AccountController::class, 'updatePassword'], 'account.password.update');

        // User management
        $r->group(['prefix' => 'users'], static function (Router $r): void {
            $r->get('/', [UserController::class, 'index'], 'users.index', ['can:users.view']);
            $r->get('/create', [UserController::class, 'create'], 'users.create', ['can:users.create']);
            $r->post('/', [UserController::class, 'store'], 'users.store', ['can:users.create']);
            $r->get('/export', [UserController::class, 'export'], 'users.export', ['can:users.export']);
            $r->get('/login-history', [UserController::class, 'loginHistory'], 'users.login-history', ['can:users.view_login_history']);
            $r->get('/{id}', [UserController::class, 'show'], 'users.show', ['can:users.view']);
            $r->get('/{id}/edit', [UserController::class, 'edit'], 'users.edit', ['can:users.edit']);
            $r->post('/{id}', [UserController::class, 'update'], 'users.update', ['can:users.edit']);
            $r->post('/{id}/roles', [UserController::class, 'updateRoles'], 'users.roles', ['can:users.assign_roles']);
            $r->post('/{id}/status', [UserController::class, 'updateStatus'], 'users.status', ['can:users.change_status']);
            $r->post('/{id}/password', [UserController::class, 'resetPassword'], 'users.password', ['can:users.reset_password']);
        });

        $r->get('/activity', [ActivityLogController::class, 'index'], 'activity.index', ['can:activity_logs.view']);

        // Approval inbox (any user who can approve in some module)
        $r->get('/approvals', [ApprovalController::class, 'index'], 'approvals.index');
        $r->get('/approvals/{id}', [ApprovalController::class, 'show'], 'approvals.show');
        $r->post('/approvals/{id}/approve', [ApprovalController::class, 'approve'], 'approvals.approve');
        $r->post('/approvals/{id}/reject', [ApprovalController::class, 'reject'], 'approvals.reject');
        $r->post('/approvals/{id}/cancel', [ApprovalController::class, 'cancel'], 'approvals.cancel');

        $r->post('/access-requests', [AccessRequestController::class, 'store'], 'access-requests.store');

        // ---------- Super Admin Control Center (real Super Admins only — never delegable) ----------
        $r->group(['prefix' => 'admin', 'middleware' => ['super-admin']], static function (Router $r): void {
            $r->get('/', [ControlCenterController::class, 'overview'], 'admin.overview');

            $r->get('/users/{id}', [AdminUserController::class, 'show'], 'admin.users.show');
            foreach (['overrides', 'restrict-module', 'data-scope', 'fields', 'resources', 'template', 'suspend', 'unsuspend', 'expiry', 'restrictions', 'force-logout', 'delete', 'restore'] as $action) {
                $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));
                $r->post('/users/{id}/' . $action, [AdminUserController::class, $method], 'admin.users.' . $action);
            }

            $r->get('/roles', [RoleController::class, 'index'], 'admin.roles');
            $r->get('/roles/create', [RoleController::class, 'create'], 'admin.roles.create');
            $r->post('/roles', [RoleController::class, 'store'], 'admin.roles.store');
            $r->get('/roles/{id}', [RoleController::class, 'show'], 'admin.roles.show');
            $r->post('/roles/{id}', [RoleController::class, 'update'], 'admin.roles.update');
            foreach (['duplicate', 'delete', 'permissions', 'copy', 'data-scope', 'fields'] as $action) {
                $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $action))));
                $r->post('/roles/{id}/' . $action, [RoleController::class, $method], 'admin.roles.' . $action);
            }

            $r->get('/permissions', [RoleController::class, 'matrix'], 'admin.permissions');
            $r->get('/actions', [AccessController::class, 'actions'], 'admin.actions');
            $r->post('/modules/{id}/toggle', [AccessController::class, 'toggleModule'], 'admin.modules.toggle');
            $r->post('/actions/{id}/toggle', [AccessController::class, 'toggleAction'], 'admin.actions.toggle');
            $r->get('/data-visibility', [AccessController::class, 'dataVisibility'], 'admin.data');
            $r->post('/data-visibility', [AccessController::class, 'saveDataVisibility'], 'admin.data.save');
            $r->get('/fields', [AccessController::class, 'fields'], 'admin.fields');
            $r->post('/fields', [AccessController::class, 'saveFields'], 'admin.fields.save');
            $r->get('/resources', [AccessController::class, 'resources'], 'admin.resources');
            $r->get('/preview', [AccessController::class, 'preview'], 'admin.preview');

            $r->get('/workflows', [WorkflowController::class, 'index'], 'admin.workflows');
            $r->get('/workflows/requests', [WorkflowController::class, 'requests'], 'admin.workflows.requests');
            $r->get('/workflows/{id}', [WorkflowController::class, 'show'], 'admin.workflows.show');
            $r->post('/workflows/{id}', [WorkflowController::class, 'update'], 'admin.workflows.update');
            $r->post('/workflows/{id}/steps', [WorkflowController::class, 'steps'], 'admin.workflows.steps');

            $r->get('/templates', [TemplateController::class, 'index'], 'admin.templates');
            $r->get('/templates/create', [TemplateController::class, 'create'], 'admin.templates.create');
            $r->post('/templates', [TemplateController::class, 'store'], 'admin.templates.store');
            $r->get('/templates/{id}', [TemplateController::class, 'show'], 'admin.templates.show');
            $r->post('/templates/{id}', [TemplateController::class, 'update'], 'admin.templates.update');
            $r->post('/templates/{id}/duplicate', [TemplateController::class, 'duplicate'], 'admin.templates.duplicate');
            $r->post('/templates/{id}/delete', [TemplateController::class, 'delete'], 'admin.templates.delete');
            $r->post('/templates/{id}/apply', [TemplateController::class, 'apply'], 'admin.templates.apply');

            $r->get('/sessions', [ControlCenterController::class, 'sessions'], 'admin.sessions');
            $r->post('/sessions/{id}/revoke', [ControlCenterController::class, 'revokeSession'], 'admin.sessions.revoke');
            $r->get('/audit', [ControlCenterController::class, 'audit'], 'admin.audit');
            $r->get('/audit/{id}', [ControlCenterController::class, 'auditEntry'], 'admin.audit.show');
            $r->get('/security', [ControlCenterController::class, 'security'], 'admin.security');
            $r->post('/security', [ControlCenterController::class, 'saveSecurity'], 'admin.security.save');
            $r->get('/access-requests', [ControlCenterController::class, 'accessRequests'], 'admin.access-requests');
            $r->post('/access-requests/{id}', [ControlCenterController::class, 'decideAccessRequest'], 'admin.access-requests.decide');
        });

        $r->get('/settings', [SettingsController::class, 'index'], 'settings.index', ['can:settings.view']);
        $r->post('/settings', [SettingsController::class, 'update'], 'settings.update', ['can:settings.edit']);
    });

    // ---------- JSON API (session-authenticated; same Gate, record and field rules) ----------
    $r->group(['prefix' => 'api', 'middleware' => ['auth']], static function (Router $r): void {
        $r->get('/users', [UserApiController::class, 'index'], 'api.users', ['can:users.view']);
        $r->get('/users/{id}', [UserApiController::class, 'show'], 'api.users.show', ['can:users.view']);
    });
};
