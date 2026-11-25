<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Bank\BankAccountService;
use App\Services\Bank\BankMasterService;
use App\Services\SettingsService;

/** Bank settings: account types (manage_account_types) and bank rules (manage_settings). */
final class BankSettingsController extends BankController
{
    public function index(Request $request): Response
    {
        $defs = array_filter(SettingsService::definitions(), static fn (array $d): bool => ($d['group'] ?? '') === 'bank');
        $values = [];
        foreach (array_keys($defs) as $k) {
            $values[$k] = App::settings()->get($k);
        }
        return $this->view('bank/settings', [
            'title' => 'Bank settings', 'breadcrumbs' => self::CRUMB + ['Settings' => null],
            'types' => (new BankAccountService(App::db()))->types(false), 'usage' => (new BankMasterService(App::db()))->usage(),
            'definitions' => $defs, 'values' => $values, 'workflows' => App::db()->select("SELECT w.workflow_key, w.name, w.is_active, w.approve_action,
                (SELECT COUNT(*) FROM approval_workflow_steps s WHERE s.workflow_id = w.id) AS steps FROM approval_workflows w WHERE w.workflow_key LIKE 'bank_%' ORDER BY w.name"),
        ]);
    }

    public function storeType(Request $request): Response
    {
        (new BankMasterService(App::db()))->saveType($request->all(), $this->user());
        return $this->redirectRoute('bank.settings')->with('success', 'Account type added.');
    }

    public function updateType(Request $request, string $id): Response
    {
        (new BankMasterService(App::db()))->saveType($request->all(), $this->user(), $this->intParam($id));
        return $this->redirectRoute('bank.settings')->with('success', 'Account type updated.');
    }

    public function rules(Request $request): Response
    {
        App::settings()->update($request->all(), (int) $this->user()['id'], ['bank']);
        return $this->redirectRoute('bank.settings')->with('success', 'Bank rules saved.');
    }
}
