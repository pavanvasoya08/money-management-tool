<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\Role;
use App\Services\SettingsService;

final class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('settings/index', [
            'title' => 'Settings',
            'breadcrumbs' => ['Settings' => null],
            'definitions' => SettingsService::definitions(),
            'values' => App::settings()->all(),
            'canEdit' => can('settings.edit'),
            'roleGrants' => Role::grantsSummary(),
            'system' => [
                'PHP version' => PHP_VERSION,
                'Database driver' => App::db()->driver(),
                'Password hashing' => defined('PASSWORD_ARGON2ID') ? 'Argon2id' : 'bcrypt',
                'Environment' => (string) config('app.env'),
                'App version' => (string) config('app.version'),
            ],
        ]);
    }

    public function update(Request $request): Response
    {
        $changed = App::settings()->update($request->all(), (int) $this->user()['id']);
        if ($changed === []) {
            return $this->redirectRoute('settings.index')->with('info', 'No changes to save.');
        }
        App::activity()->log('settings.updated', 'Updated settings: ' . implode(', ', $changed), 'system_settings', null, ['keys' => $changed]);
        return $this->redirectRoute('settings.index')->with('success', 'Settings saved.');
    }
}
