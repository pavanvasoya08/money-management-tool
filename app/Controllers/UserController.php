<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Paginator;
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
        $me = $this->user();
        if ($filters['status'] === 'deleted' && !can('users.restore')) {
            $filters['status'] = '';
        }
        // Record-level visibility is applied in SQL; field rules before rendering.
        $scope = App::dataAccess()->constrain($me, 'users', 'u');
        $page = User::paginate($filters, max(1, (int) $request->input('page', 1)), (int) setting('records_per_page', 15), $scope);
        $users = new \App\Core\Paginator(App::fields()->presentMany($me, 'users', $page->items), $page->total, $page->page, $page->perPage);
        return $this->view('users/index', [
            'title' => 'Users',
            'breadcrumbs' => ['User Management' => null],
            'users' => $users,
            'filters' => $filters,
            'roles' => Role::allActive(),
            'counts' => User::counts($scope),
            'scopeLabel' => App::dataAccess()->resolve($me, 'users')->describe(),
        ] + (App::gate()->isSuperAdmin($me) ? ['section' => 'users'] : []) + [
            'fieldAccess' => App::fields()->accessMap($me, 'users'),
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
        $target = App::fields()->present($this->user(), 'users', $target);
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
            'fieldAccess' => App::fields()->accessMap($this->user(), 'users'),
        ]);
    }

    public function edit(Request $request, string $id): Response
    {
        $target = $this->findUser($id);
        $this->authorize('users.edit', $target);
        $access = App::fields()->accessMap($this->user(), 'users');
        $target = App::fields()->present($this->user(), 'users', $target);
        return $this->view('users/form', [
            'fieldAccess' => $access,
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
        // Fields the user may not edit are rejected even in crafted requests.
        $submitted = array_intersect_key($request->all(), array_flip(['name', 'email', 'phone']));
        App::fields()->assertWritable($this->user(), 'users', $submitted, $target);
        $access = App::fields()->accessMap($this->user(), 'users');
        $rules = [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:191|unique:users,email,' . $target['id'],
            'phone' => 'nullable|string|max:20|regex:/^[0-9+\-\s()]{6,20}$/',
        ];
        foreach ($rules as $field => $rule) {
            if (isset($access[$field]) && !$access[$field]->canEdit()) {
                unset($rules[$field]); // locked fields keep their stored value
            }
        }
        $data = $this->validate($request, $rules) + array_intersect_key($target, array_diff_key(array_flip(['name', 'email', 'phone']), $rules));
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

    /** CSV export — same record scope and field rules as the page. */
    public function export(Request $request): Response
    {
        $me = $this->user();
        $filters = ['q' => mb_substr($request->string('q'), 0, 100), 'status' => $request->string('status'), 'role' => $request->string('role')];
        if ($filters['status'] === 'deleted' && !can('users.restore')) {
            $filters['status'] = '';
        }
        $all = [];
        $page = 1;
        do {
            $chunk = User::paginate($filters, $page, 100, App::dataAccess()->constrain($me, 'users', 'u'));
            foreach (App::fields()->presentMany($me, 'users', $chunk->items) as $u) {
                $all[] = $u;
            }
            $page++;
        } while ($page <= $chunk->lastPage && $page <= 100);
        $columns = ['id' => 'ID', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'status' => 'Status', 'roles' => 'Roles', 'last_login_at' => 'Last sign-in (UTC)', 'last_login_ip' => 'Last sign-in IP', 'created_at' => 'Created (UTC)'];
        // Hidden fields are removed from the header as well as the rows.
        $access = App::fields()->accessMap($me, 'users');
        $columns = array_filter($columns, static fn (string $k): bool => !isset($access[$k]) || $access[$k]->canView(), ARRAY_FILTER_USE_KEY);
        $out = fopen('php://temp', 'w+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_values($columns), ',', '"', '');
        foreach ($all as $u) {
            $line = [];
            foreach (array_keys($columns) as $k) {
                $v = $k === 'roles' ? implode('; ', array_column($u['roles'] ?? [], 'name')) : ($u[$k] ?? '');
                $line[] = is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v; // CSV formula-injection guard
            }
            fputcsv($out, $line, ',', '"', '');
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);
        App::activity()->log('users.exported', 'Exported ' . count($all) . ' user record(s) to CSV', module: 'users', newValues: ['rows' => count($all), 'columns' => array_keys($columns), 'filters' => $filters]);
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-' . gmdate('Ymd-His') . '.csv"',
        ]);
    }

    /**
     * Load a user the current user is allowed to see (record-level scope).
     * Unauthorized IDs return 404 so changing the ID in the URL reveals nothing.
     * @return array<string, mixed>
     */
    private function findUser(string $id): array
    {
        $userId = $this->intParam($id);
        $user = User::find($userId);
        if ($user === null || (!empty($user['deleted_at']) && !App::gate()->isSuperAdmin($this->user()))) {
            throw new HttpException(404, 'User not found.');
        }
        App::dataAccess()->assertCanAccess($this->user(), 'users', $userId);
        return $user;
    }

    /** Roles the current user may assign (Super Admin role only for Super Admins). @return list<array<string, mixed>> */
    private function assignableRoles(): array
    {
        $isSuper = App::gate()->isSuperAdmin($this->user());
        return array_values(array_filter(Role::allActive(), static fn (array $r): bool => $isSuper || !$r['is_super_admin']));
    }
}
