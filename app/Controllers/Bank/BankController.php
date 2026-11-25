<?php

declare(strict_types=1);

namespace App\Controllers\Bank;

use App\Core\App;
use App\Core\Controller;
use App\Exceptions\HttpException;
use App\Services\Bank\BankAccountService;

/**
 * Shared helpers for the bank pages. Every record is loaded through the user's bank-account
 * record scope (404 outside it); every action also checks its own permission.
 */
abstract class BankController extends Controller
{
    protected const CRUMB = ['Bank Management' => '/bank'];

    /** @return array{0: string, 1: list<mixed>} */
    protected function scope(string $alias = 'a'): array
    {
        return App::dataAccess()->constrain($this->user(), 'bank_accounts', $alias);
    }

    /** @return array<string, mixed> */
    protected function findAccount(string|int $id): array
    {
        $aid = is_int($id) ? $id : $this->intParam($id);
        App::dataAccess()->assertCanAccess($this->user(), 'bank_accounts', $aid);
        return (new BankAccountService(App::db()))->find($aid) ?? throw new HttpException(404, 'Bank account not found.');
    }

    /** Accounts the user can see, for pickers. @return list<array<string, mixed>> */
    protected function accountOptions(bool $usableOnly = false): array
    {
        [$w, $p] = $this->scope();
        return App::db()->select("SELECT a.id, a.account_code, a.display_name, a.bank_name, a.account_number_last4, a.currency, a.is_central_funding, a.status FROM bank_accounts a
            WHERE {$w} AND a.is_archived = 0" . ($usableOnly ? " AND a.status = 'active'" : '') . ' ORDER BY a.is_central_funding DESC, a.display_name', $p);
    }

    /** @return list<array<string, mixed>> */
    protected function partnerOptions(): array
    {
        if (!can('partners.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'partners', 'p');
        return App::db()->select("SELECT p.id, p.partner_code, p.name FROM partners p WHERE {$w} AND p.status <> 'archived' ORDER BY p.name", $p);
    }

    /** @return list<array<string, mixed>> */
    protected function gstOptions(): array
    {
        if (!can('gst_registrations.view')) {
            return [];
        }
        [$w, $p] = App::dataAccess()->constrain($this->user(), 'gst_registrations', 'g');
        return App::db()->select("SELECT g.id, g.gstin, g.legal_name, g.trade_name FROM gst_registrations g WHERE {$w} AND g.is_archived = 0 AND g.status <> 'cancelled' ORDER BY g.gstin", $p);
    }

    /** @return list<array<string, mixed>> */
    protected function activityOptions(): array
    {
        return App::db()->select('SELECT id, code, name FROM gst_business_activities WHERE is_active = 1 ORDER BY sort_order, name');
    }

    /** @return array<string, mixed> */
    protected function filters(\App\Core\Request $request, array $text, array $ints): array
    {
        $f = [];
        foreach ($text as $k) {
            $f[$k] = mb_substr($request->string($k), 0, 100);
            if (in_array($k, ['from', 'to'], true) && $f[$k] !== '' && !\App\Core\Validator::isDate($f[$k])) {
                $f[$k] = '';
            }
        }
        foreach ($ints as $k) {
            $f[$k] = max(0, (int) $request->input($k, 0)) ?: '';
        }
        return $f;
    }
}
