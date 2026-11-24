<?php

declare(strict_types=1);

namespace App\Controllers\Gst;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Exceptions\HttpException;
use App\Services\Gst\GstMasterService;
use App\Services\SettingsService;

/** GST masters (states, registration types, relationship types, business activities) and GST business rules. */
final class GstMasterController extends Controller
{
    public function index(Request $request): Response
    {
        $m = new GstMasterService(App::db());
        $tab = $request->string('tab', 'states');
        if (!isset(GstMasterService::MASTERS[$tab]) && $tab !== 'rules') {
            $tab = 'states';
        }
        return $this->view('gst/masters', [
            'title' => 'GST masters & rules',
            'breadcrumbs' => ['GST Management' => route('gst.dashboard'), 'Masters & rules' => null],
            'tab' => $tab,
            'rows' => match ($tab) {
                'states' => $m->states(), 'types' => $m->types(), 'relationships' => $m->relationships(), 'activities' => $m->activities(), default => [],
            },
            'usage' => $tab !== 'rules' ? $m->usage($tab) : [],
            'definitions' => array_filter(SettingsService::definitions(), static fn (array $d): bool => $d['group'] === 'gst'),
            'values' => App::settings()->all(),
        ]);
    }

    public function save(Request $request, string $master, ?string $id = null): Response
    {
        if (!isset(GstMasterService::MASTERS[$master])) {
            throw new HttpException(404);
        }
        (new GstMasterService(App::db()))->save($master, $request->all(), $this->user(), $id !== null ? $this->intParam($id) : null);
        return $this->redirect(route('gst.masters', ['tab' => $master]))->with('success', $id === null ? 'Added.' : 'Saved.');
    }

    public function update(Request $request, string $master, string $id): Response
    {
        return $this->save($request, $master, $id);
    }

    public function rules(Request $request): Response
    {
        $input = $request->all();
        foreach (SettingsService::definitions() as $key => $def) {
            if ($def['group'] === 'gst' && $def['type'] === 'boolean' && !isset($input[$key])) {
                $input[$key] = '0';
            }
        }
        $changed = App::settings()->update($input, (int) $this->user()['id'], ['gst']);
        if ($changed !== []) {
            App::activity()->log('gst.rules_updated', 'Updated GST business rules: ' . implode(', ', $changed), 'system_settings', null, module: 'gst_registrations',
                oldValues: App::settings()->lastChange['old'], newValues: App::settings()->lastChange['new']);
        }
        return $this->redirect(route('gst.masters', ['tab' => 'rules']))->with($changed === [] ? 'info' : 'success', $changed === [] ? 'No changes to save.' : 'GST business rules saved.');
    }
}
