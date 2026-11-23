<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Admin\Catalog;
use App\Services\Admin\TemplateService;

final class TemplateController extends AdminController
{
    public function index(Request $request): Response
    {
        return $this->admin('admin/templates/index', 'templates', 'Permission templates', [
            'templates' => App::db()->select(
                'SELECT t.*, (SELECT COUNT(*) FROM permission_template_items i WHERE i.template_id = t.id) AS permission_count,
                        (SELECT COUNT(*) FROM permission_template_scopes s WHERE s.template_id = t.id) AS scope_count
                 FROM permission_templates t ORDER BY t.name'
            ),
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function show(Request $request, string $id): Response
    {
        return $this->form($this->find($id));
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, ['name' => 'required|string|min:2|max:100', 'description' => 'nullable|string|max:255']);
        $id = (new TemplateService())->save(null, $data, array_values((array) $request->input('permissions', [])), $this->scopes($request), $this->user());
        return $this->redirect(route('admin.templates.show', ['id' => $id]))->with('success', 'Template created.');
    }

    public function update(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $data = $this->validate($request, ['name' => 'required|string|min:2|max:100', 'description' => 'nullable|string|max:255']);
        (new TemplateService())->save((int) $t['id'], $data, array_values((array) $request->input('permissions', [])), $this->scopes($request), $this->user());
        return $this->redirect(route('admin.templates.show', ['id' => $t['id']]))->with('success', 'Template saved.');
    }

    public function duplicate(Request $request, string $id): Response
    {
        $newId = (new TemplateService())->duplicate((int) $this->find($id)['id'], $this->user());
        return $this->redirect(route('admin.templates.show', ['id' => $newId]))->with('success', 'Template duplicated.');
    }

    public function delete(Request $request, string $id): Response
    {
        (new TemplateService())->delete((int) $this->find($id)['id'], $this->user());
        return $this->redirect(route('admin.templates'))->with('success', 'Template deleted.');
    }

    public function apply(Request $request, string $id): Response
    {
        $t = $this->find($id);
        $svc = new TemplateService();
        if ($request->string('target') === 'role') {
            $svc->applyToRole((int) $t['id'], (int) $request->input('role_id', 0), $this->user());
            return $this->redirect(route('admin.roles.show', ['id' => (int) $request->input('role_id', 0)]))->with('success', "Template \"{$t['name']}\" applied to the role.");
        }
        $userId = (int) $request->input('user_id', 0);
        $n = $svc->applyToUser((int) $t['id'], $userId, $this->user());
        return $this->redirect(route('admin.users.show', ['id' => $userId, 'tab' => 'permissions']))->with('success', "Template applied: {$n} permission(s) added.");
    }

    /** @return array<string, string> */
    private function scopes(Request $request): array
    {
        return array_filter((array) $request->input('scopes', []), static fn ($v, $k): bool => is_string($v) && is_string($k), ARRAY_FILTER_USE_BOTH);
    }

    /** @return array<string, mixed> */
    private function find(string $id): array
    {
        return App::db()->first('SELECT * FROM permission_templates WHERE id = ?', [$this->intParam($id)]) ?? throw new HttpException(404, 'Template not found.');
    }

    /** @param array<string, mixed>|null $t */
    private function form(?array $t): Response
    {
        $granted = [];
        $scopes = [];
        if ($t !== null) {
            $granted = array_map('intval', array_column(App::db()->select('SELECT permission_id FROM permission_template_items WHERE template_id = ?', [$t['id']]), 'permission_id'));
            foreach (App::db()->select('SELECT m.module_key, s.scope FROM permission_template_scopes s JOIN modules m ON m.id = s.module_id WHERE s.template_id = ?', [$t['id']]) as $s) {
                $scopes[$s['module_key']] = $s['scope'];
            }
        }
        return $this->view('admin/templates/form', [
            'title' => $t ? $t['name'] : 'Create template', 'section' => 'templates',
            'breadcrumbs' => ['Control Center' => route('admin.overview'), 'Permission templates' => route('admin.templates'), ($t ? $t['name'] : 'Create') => null],
            'template' => $t, 'matrix' => Catalog::matrix(), 'columns' => Catalog::COLUMNS, 'granted' => $granted, 'scopes' => $scopes,
            'recordModules' => Catalog::recordModules(),
            'roles' => array_values(array_filter(Catalog::roles(), static fn (array $r): bool => !$r['is_super_admin'])),
            'users' => App::db()->select('SELECT id, name FROM users WHERE deleted_at IS NULL AND id <> ? ORDER BY name', [(int) $this->user()['id']]),
        ]);
    }
}
