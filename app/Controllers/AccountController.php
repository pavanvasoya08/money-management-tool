<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\PasswordPolicy;
use App\Services\UserService;

final class AccountController extends Controller
{
    public function profile(Request $request): Response
    {
        $user = $this->user();
        return $this->view('account/profile', [
            'title' => 'My profile',
            'breadcrumbs' => ['My profile' => null],
            'profile' => $user,
            'roles' => User::roles((int) $user['id']),
            'permissions' => App::gate()->permissionSlugs($user),
            'isSuperAdmin' => App::gate()->isSuperAdmin($user),
            'logins' => LoginAttempt::recent(10, (int) $user['id']),
        ]);
    }

    public function showPassword(Request $request): Response
    {
        return $this->view('account/password', [
            'title' => 'Change password',
            'breadcrumbs' => ['My profile' => route('account.profile'), 'Change password' => null],
            'forced' => (int) $this->user()['must_change_password'] === 1,
            'policy' => PasswordPolicy::description(),
        ]);
    }

    public function updatePassword(Request $request): Response
    {
        $data = $this->validate($request, [
            'current_password' => 'required|string',
            'password' => 'required|string|confirmed',
        ], ['current_password' => 'Current password', 'password' => 'New password']);

        (new UserService())->changeOwnPassword($this->user(), (string) $data['current_password'], (string) $data['password']);
        return $this->redirectRoute('dashboard')->with('success', 'Your password has been changed.');
    }
}
