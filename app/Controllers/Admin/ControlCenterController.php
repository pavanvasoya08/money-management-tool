<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Database;
use App\Core\Paginator;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Admin\AccessRequestService;
use App\Services\Admin\Catalog;
use App\Services\SettingsService;

final class ControlCenterController extends AdminController
{
    public function overview(Request $request): Response
    {
        return $this->admin('admin/overview', 'overview', 'Overview', [
            'stats' => Catalog::stats(),
            'accessRequestsEnabled' => AccessRequestService::enabled(),
            'permissionChanges' => Catalog::recentAudit(['permission.', 'data_visibility.', 'field_visibility.', 'role.', 'template.', 'resource.'], 8),
            'adminActivity' => Catalog::recentAudit(['user.', 'workflow.', 'settings.', 'security.', 'session.', 'access_request.'], 8),
        ]);
    }

    public function sessions(Request $request): Response
    {
        $userId = (int) $request->input('user', 0) ?: null;
        return $this->admin('admin/sessions', 'sessions', 'Login sessions', [
            'sessions' => App::sessions()->active($userId),
            'filterUser' => $userId ? \App\Models\User::find($userId) : null,
            'currentSession' => App::auth()->sessionRowId(),
            'failed' => App::db()->select('SELECT la.*, u.name AS user_name FROM login_attempts la LEFT JOIN users u ON u.id = la.user_id WHERE la.successful = 0 ORDER BY la.attempted_at DESC LIMIT 15'),
        ]);
    }

    public function revokeSession(Request $request, string $id): Response
    {
        $row = App::db()->first('SELECT * FROM user_sessions WHERE id = ?', [$this->intParam($id)]) ?? throw new HttpException(404, 'Session not found.');
        if ((int) $row['id'] === App::auth()->sessionRowId()) {
            return $this->back($request, route('admin.sessions'))->with('error', 'Use "Sign out" to end your own current session.');
        }
        App::sessions()->revoke((int) $row['id'], (int) $this->user()['id'], 'Revoked by Super Admin');
        App::activity()->log('session.revoked', 'Revoked a login session', 'user', (int) $row['user_id'], module: 'super_admin',
            oldValues: ['session' => (int) $row['id'], 'ip' => $row['ip_address']], newValues: ['revoked' => true]);
        return $this->back($request, route('admin.sessions'))->with('success', 'Session revoked. The device is signed out on its next request.');
    }

    public function audit(Request $request): Response
    {
        $f = [
            'actor' => (int) $request->input('actor', 0),
            'affected' => (int) $request->input('affected', 0),
            'role' => (int) $request->input('role', 0),
            'module' => preg_match('/^[a-z_]*$/', $request->string('module')) ? $request->string('module') : '',
            'action' => preg_match('/^[a-z_.]*$/', $request->string('action')) ? $request->string('action') : '',
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string('from')) ? $request->string('from') : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $request->string('to')) ? $request->string('to') : '',
            'q' => mb_substr($request->string('q'), 0, 100),
        ];
        $where = ['1 = 1'];
        $params = [];
        foreach (['actor' => 'a.user_id', 'affected' => 'a.affected_user_id', 'role' => 'a.affected_role_id'] as $k => $col) {
            if ($f[$k] > 0) {
                $where[] = "{$col} = ?";
                $params[] = $f[$k];
            }
        }
        if ($f['module'] !== '') {
            $where[] = 'a.module = ?';
            $params[] = $f['module'];
        }
        if ($f['action'] !== '') {
            $where[] = "a.action LIKE ? ESCAPE '!'";
            $params[] = Database::likeEscape($f['action']) . '%';
        }
        if ($f['from'] !== '') {
            $where[] = 'a.created_at >= ?';
            $params[] = (new \DateTimeImmutable($f['from'] . ' 00:00:00', display_tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($f['to'] !== '') {
            $where[] = 'a.created_at <= ?';
            $params[] = (new \DateTimeImmutable($f['to'] . ' 23:59:59', display_tz()))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        }
        if ($f['q'] !== '') {
            $where[] = "(a.description LIKE ? ESCAPE '!' OR a.ip_address LIKE ? ESCAPE '!')";
            $like = Database::likeContains($f['q']);
            array_push($params, $like, $like);
        }
        $logs = Paginator::query(
            App::db(),
            'a.*, u.name AS user_name, au.name AS affected_user_name, r.name AS affected_role_name',
            'FROM activity_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users au ON au.id = a.affected_user_id LEFT JOIN roles r ON r.id = a.affected_role_id WHERE ' . implode(' AND ', $where),
            'a.created_at DESC, a.id DESC',
            $params,
            max(1, (int) $request->input('page', 1)),
            25
        );
        return $this->admin('admin/audit', 'audit', 'Audit log', [
            'logs' => $logs,
            'f' => $f,
            'users' => App::db()->select('SELECT id, name FROM users ORDER BY name'),
            'roles' => App::db()->select('SELECT id, name FROM roles ORDER BY name'),
            'modules' => array_column(App::db()->select('SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL ORDER BY module'), 'module'),
        ]);
    }

    public function auditEntry(Request $request, string $id): Response
    {
        $log = App::db()->first(
            'SELECT a.*, u.name AS user_name, au.name AS affected_user_name, r.name AS affected_role_name FROM activity_logs a
             LEFT JOIN users u ON u.id = a.user_id LEFT JOIN users au ON au.id = a.affected_user_id LEFT JOIN roles r ON r.id = a.affected_role_id WHERE a.id = ?',
            [$this->intParam($id)]
        ) ?? throw new HttpException(404, 'Entry not found.');
        return $this->admin('admin/audit-entry', 'audit', 'Audit entry #' . $log['id'], ['log' => $log]);
    }

    public function security(Request $request): Response
    {
        $defs = array_filter(SettingsService::definitions(), static fn (array $d): bool => in_array($d['group'], ['security', 'access'], true));
        return $this->admin('admin/security', 'security', 'Security settings', ['definitions' => $defs, 'values' => App::settings()->all()]);
    }

    public function saveSecurity(Request $request): Response
    {
        $input = $request->all();
        foreach (SettingsService::definitions() as $key => $def) {
            if ($def['type'] === 'boolean' && in_array($def['group'], ['security', 'access'], true)) {
                $input[$key] = !empty($input[$key]) ? '1' : '0';
            }
        }
        $settings = App::settings();
        $changed = $settings->update($input, (int) $this->user()['id'], ['security', 'access']);
        if ($changed === []) {
            return $this->redirectRoute('admin.security')->with('info', 'No changes to save.');
        }
        App::flushAuthorization();
        App::activity()->log('security.settings_updated', 'Updated security settings: ' . implode(', ', $changed), 'system_settings', null, module: 'super_admin',
            oldValues: $settings->lastChange['old'], newValues: $settings->lastChange['new']);
        return $this->redirectRoute('admin.security')->with('success', 'Security settings saved and applied.');
    }

    public function accessRequests(Request $request): Response
    {
        return $this->admin('admin/access-requests', 'overview', 'Access requests', [
            'enabled' => AccessRequestService::enabled(),
            'requests' => App::db()->select(
                'SELECT ar.*, u.name AS user_name, u.email, p.slug, p.name AS permission_name, d.name AS decider_name FROM access_requests ar
                 JOIN users u ON u.id = ar.user_id JOIN permissions p ON p.id = ar.permission_id LEFT JOIN users d ON d.id = ar.decided_by
                 ORDER BY CASE ar.status WHEN \'pending\' THEN 0 ELSE 1 END, ar.created_at DESC LIMIT 100'
            ),
        ]);
    }

    public function decideAccessRequest(Request $request, string $id): Response
    {
        $data = $this->validate($request, ['decision' => 'required|in:approve,reject', 'note' => 'nullable|string|max:500', 'expires_at' => 'nullable|string|max:20']);
        (new AccessRequestService())->decide($this->intParam($id), $data['decision'] === 'approve', $this->user(), $data['note'], $this->utc($data['expires_at']));
        return $this->redirectRoute('admin.access-requests')->with('success', $data['decision'] === 'approve' ? 'Access granted.' : 'Request rejected.');
    }
}
