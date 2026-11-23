<?php

declare(strict_types=1);

use App\Controllers\AccountController;
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
            $r->get('/login-history', [UserController::class, 'loginHistory'], 'users.login-history', ['can:users.view_login_history']);
            $r->get('/{id}', [UserController::class, 'show'], 'users.show', ['can:users.view']);
            $r->get('/{id}/edit', [UserController::class, 'edit'], 'users.edit', ['can:users.edit']);
            $r->post('/{id}', [UserController::class, 'update'], 'users.update', ['can:users.edit']);
            $r->post('/{id}/roles', [UserController::class, 'updateRoles'], 'users.roles', ['can:users.assign_roles']);
            $r->post('/{id}/status', [UserController::class, 'updateStatus'], 'users.status', ['can:users.change_status']);
            $r->post('/{id}/password', [UserController::class, 'resetPassword'], 'users.password', ['can:users.reset_password']);
        });

        $r->get('/activity', [ActivityLogController::class, 'index'], 'activity.index', ['can:activity_logs.view']);

        $r->get('/settings', [SettingsController::class, 'index'], 'settings.index', ['can:settings.view']);
        $r->post('/settings', [SettingsController::class, 'update'], 'settings.update', ['can:settings.edit']);
    });
};
