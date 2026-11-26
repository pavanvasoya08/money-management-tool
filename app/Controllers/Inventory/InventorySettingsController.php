<?php

declare(strict_types=1);

namespace App\Controllers\Inventory;

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Services\Inventory\CorrectionService;
use App\Services\Inventory\InventoryLedger;
use App\Services\Inventory\UnitService;
use App\Services\Inventory\WarehouseService;
use App\Services\SettingsService;

/** Inventory settings: rules, default valuation method, units & conversions and warehouse types. */
final class InventorySettingsController extends InventoryBaseController
{
    public function index(Request $request): Response
    {
        $units = new UnitService(App::db());
        $defs = array_filter(SettingsService::definitions(), static fn (array $d): bool => ($d['group'] ?? '') === 'inventory');
        $values = [];
        foreach ($defs as $k => $d) {
            $values[$k] = App::settings()->get($k, $d['default']);
        }
        return $this->view('inventory/settings', ['title' => 'Inventory settings', 'breadcrumbs' => self::CRUMB + ['Settings' => null], 'defs' => $defs, 'values' => $values,
            'methods' => InventoryLedger::METHODS, 'units' => $units->units(), 'conversions' => $units->conversions(null, true), 'unitTypes' => UnitService::TYPES,
            'types' => (new WarehouseService(App::db()))->types(), 'tab' => $request->string('tab', 'rules'), 'editUnit' => (int) $request->input('unit', 0), 'editType' => (int) $request->input('type', 0)]);
    }

    public function saveRules(Request $request): Response
    {
        $this->authorize('inventory.manage_inventory_settings');
        $input = $request->all();
        foreach (['inventory_block_expired_dispatch', 'inventory_transfer_in_transit'] as $k) {
            $input[$k] = !empty($input[$k]) ? '1' : '0';
        }
        $input['inventory_valuation_method'] = (string) App::settings()->get('inventory_valuation_method', 'weighted_average');
        $changed = App::settings()->update($input, (int) $this->user()['id'], ['inventory']);
        if ($changed !== []) {
            App::activity()->log('inventory.settings_changed', 'Changed inventory settings: ' . implode(', ', $changed), module: 'inventory', newValues: array_intersect_key($input, array_flip($changed)));
        }
        return $this->back(route('inventory.settings'), $changed === [] ? 'Nothing changed.' : 'Inventory settings saved.');
    }

    public function saveMethod(Request $request): Response
    {
        $this->authorize('inventory.manage_stock_valuation');
        (new CorrectionService(App::db()))->setDefaultMethod($request->string('inventory_valuation_method'), $request->string('reason'), $this->user());
        return $this->back(route('inventory.settings'), 'Default valuation method saved.');
    }

    public function saveUnit(Request $request): Response
    {
        $this->authorize('inventory.manage_units');
        $id = (int) $request->input('id', 0) ?: null;
        (new UnitService(App::db()))->saveUnit($request->all(), $id, $this->user());
        return $this->back(route('inventory.settings', ['tab' => 'units']), $id === null ? 'Unit added.' : 'Unit saved.');
    }

    public function addConversion(Request $request): Response
    {
        $this->authorize('inventory.manage_units');
        (new UnitService(App::db()))->addConversion(array_diff_key($request->all(), ['product_id' => 1]), $this->user());
        return $this->back(route('inventory.settings', ['tab' => 'units']), 'Conversion added.');
    }

    public function endConversion(Request $request, string $id): Response
    {
        $this->authorize('inventory.manage_units');
        $cid = $this->intParam($id);
        $productId = App::db()->scalar('SELECT product_id FROM inventory_unit_conversions WHERE id = ?', [$cid]);
        (new UnitService(App::db()))->endConversion($cid, $request->string('reason'), $this->user());
        $back = $request->string('back') === 'product' && $productId !== null ? route('inventory.products.show', ['id' => $productId]) . '#units' : route('inventory.settings', ['tab' => 'units']);
        return $this->back($back, 'Conversion ended. Documents already recorded keep their quantities.');
    }

    public function saveType(Request $request): Response
    {
        $this->authorize('inventory.manage_warehouses');
        $id = (int) $request->input('id', 0) ?: null;
        (new WarehouseService(App::db()))->saveType($request->all(), $id, $this->user());
        return $this->back(route('inventory.settings', ['tab' => 'types']), $id === null ? 'Warehouse type added.' : 'Warehouse type saved.');
    }
}
