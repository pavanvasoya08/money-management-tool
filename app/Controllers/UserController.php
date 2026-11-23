<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Models\ActivityLog;
use App\Models\LoginAttempt;
use App\Models\Role;
use App\Models\User;
use App\Services\PasswordPolicy;
use App\Services\UserService;

final class UserController extends Controller
{
    private UserService $users;

    public function __construct()
    {
        $this->users = new UserService();
    }

    public function index(Request $request): Response
    {
        $filters = [
            'q' => mb_substr($request->string('q'), 0, 100),
            'status' => $request->string('status'),
            'role' => $request->string('role'),
        ];
        return $this->view('users/index', [
            'title' => 'Users',
            'breadcrumbs' => ['User Management' => null],
            'users' => User::paginate($filters, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15)),
            'filters' => $filters,
            'roles' => Role::allActive(),
            'counts' => User::counts(),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('users/form', [
            'title' => 'Create user',
            'breadcrumbs' => ['User Management' => route('users.index'), 'Create user' => null],
            'target' => null,
            'roles' => $this->assignableRoles(),
            'selectedRoles' => array_map('intval', (array) old('roles', [])),
            'canAssignRoles' => can('users.assign_roles'),
            'policy' => PasswordPolicy::description(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:191|unique:users,email',
            'phone' => 'nullable|string|max:20|regex:/^[0-9+\-\s()]{6,20}$/',
            'password' => 'required|string|confirmed',
            'roles' => 'nullable|array',
        ], ['password' => 'Temporary password']);

        $id = $this->users->create($data, $this->user());
        return $this->redirectRoute('users.show', ['id' => $id])
            ->with('success', 'User created. They must change the temporary password at first sign-in.');
    }

    public function show(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $targetId = (int) $target['id'];
        return $this->view('users/show', [
            'title' => $target['name'],
            'breadcrumbs' => ['User Management' => route('users.index'), $target['name'] => null],
            'target' => $target,
            'targetRoles' => User::roles($targetId),
            'targetIsSuper' => App::gate()->isSuperAdmin($targetId),
            'isSelf' => $targetId === (int) $this->user()['id'],
            'logins' => can('users.view_login_history') ? LoginAttempt::recent(10, $targetId) : null,
            'activity' => can('activity_logs.view') ? ActivityLog::paginate(['subject_type' => 'user', 'subject_id' => $targetId], 1, 10)->items : null,
            'roles' => $this->assignableRoles(),
            'selectedRoles' => User::roleIds($targetId),
            'creator' => $target['created_by'] ? User::find((int) $target['created_by']) : null,
            'policy' => PasswordPolicy::description(),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $this->authorize('users.edit', $target);
        return $this->view('users/form', [
            'title' => 'Edit ' . $target['name'],
            'breadcrumbs' => ['User Management' => route('users.index'), $target['name'] => route('users.show', ['id' => $target['id']]), 'Edit' => null],
            'target' => $target,
            'roles' => [],
            'selectedRoles' => [],
            'canAssignRoles' => false,
            'policy' => null,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $this->authorize('users.edit', $target);
        $data = $this->validate($request, [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:191|unique:users,email,' . $target['id'],
            'phone' => 'nullable|string|max:20|regex:/^[0-9+\-\s()]{6,20}$/',
        ]);
        $this->users->update($target, $data, $this->user());
        return $this->redirectRoute('users.show', ['id' => $target['id']])->with('success', 'User details saved.');
    }

    public function updateRoles(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $this->authorize('users.assign_roles', $target);
        $data = $this->validate($request, ['roles' => 'nullable|array']);
        $this->users->syncRoles($target, $data['roles'], $this->user());
        return $this->redirectRoute('users.show', ['id' => $target['id']])->with('success', 'Roles updated.');
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $this->authorize('users.change_status', $target);
        $data = $this->validate($request, ['status' => 'required|in:active,inactive']);
        $this->users->setStatus($target, (string) $data['status'], $this->user());
        return $this->redirectRoute('users.show', ['id' => $target['id']])
            ->with('success', $data['status'] === 'active' ? 'User activated.' : 'User deactivated and signed out of all devices.');
    }

    public function resetPassword(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $this->authorize('users.reset_password', $target);
        $data = $this->validate($request, ['password' => 'required|string|confirmed'], ['password' => 'Temporary password']);
        $this->users->adminResetPassword($target, (string) $data['password'], $this->user());
        return $this->redirectRoute('users.show', ['id' => $target['id']])
            ->with('success', 'Temporary password set. Share it securely — the user must change it at next sign-in.');
    }

    public function loginHistory(Request $request): Response
    {
        $filters = ['q' => mb_substr($request->string('q'), 0, 100), 'result' => $request->string('result')];
        return $this->view('users/login-history', [
            'title' => 'Login history',
            'breadcrumbs' => ['User Management' => can('users.view') ? route('users.index') : null, 'Login history' => null],
            'attempts' => LoginAttempt::paginate($filters, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15)),
            'filters' => $filters,
        ]);
    }

    /** @return array<string, mixed> */
    private function findUser(string $id): array
    {
        return User::find($this->intParam($id)) ?? throw new HttpException(404, 'User not found.');
    }

    /** Roles the current user may assign (Super Admin role only for Super Admins). @return list<array<string, mixed>> */
    private function assignableRoles(): array
    {
        $isSuper = App::gate()->isSuperAdmin($this->user());
        return array_values(array_filter(Role::allActive(), static fn (array $r): bool => $isSuper || !$r['is_super_admin']));
    }
}
