<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\ActivityLog;
use App\Models\LoginAttempt;
use App\Models\User;

/**
 * Phase 1 dashboard: real system data only (users, logins, activity).
 * No financial figures are shown until the finance modules exist.
 */
final class DashboardController extends Controller
{
    public function home(Request $request): Response
    {
        return $this->redirect(App::auth()->check() ? route('dashboard') : route('login'));
    }

    public function index(Request $request): Response
    {
        $user = $this->user();
        $gate = App::gate();
        $userId = (int) $user['id'];
        $canUsers = $gate->allows($user, 'users.view');
        $canLogins = $gate->allows($user, 'users.view_login_history');
        $canActivity = $gate->allows($user, 'activity_logs.view');

        return $this->view('dashboard/index', [
            'title' => 'Dashboard',
            'breadcrumbs' => ['Dashboard' => null],
            'me' => $user,
            'roles' => User::roles($userId),
            'isSuperAdmin' => $gate->isSuperAdmin($user),
            'canUsers' => $canUsers,
            'canLogins' => $canLogins,
            'canActivity' => $canActivity,
            'counts' => $canUsers ? User::counts() : null,
            'logins' => LoginAttempt::recent(8, $canLogins ? null : $userId),
            'activity' => ActivityLog::recent(8, $canActivity ? null : $userId),
            'chart' => $canLogins ? LoginAttempt::dailyCounts(14) : null,
            'capital' => $capital = $this->capitalWidgets($user),
            'withCharts' => $canLogins || !empty($capital['chart']),
            'bank' => $this->bankWidget($user),
        ]);
    }

    /** Phase 5 widget: accounts and balances within the user's bank-account scope. @param array<string, mixed> $user @return array<string, mixed>|null */
    private function bankWidget(array $user): ?array
    {
        if (!App::gate()->allows($user, 'bank_accounts.view_dashboard')) {
            return null;
        }
        $data = (new \App\Services\Bank\BankDashboardService(App::db()))->data(['archived' => ''], App::dataAccess()->constrain($user, 'bank_accounts', 'a'));
        $monthStart = substr(\App\Support\BusinessDate::today(), 0, 7) . '-01';
        $inr = array_map(static fn (array $a): int => (int) $a['id'], array_filter($data['accounts'], static fn (array $a): bool => $a['currency'] === 'INR'));
        return ['total' => $data['total'], 'active' => $data['counts']['active'], 'current' => $data['current'], 'pendingRec' => count($data['pendingRec']), 'awaiting' => $data['awaiting'],
            'month' => (new \App\Services\Bank\BankLedgerService(App::db()))->movement(array_values($inr), $monthStart, null), 'central' => $data['central'],
            'amounts' => App::fields()->access($user, 'bank_accounts', 'current_balance')];
    }

    /**
     * Phase 3 widgets — computed from posted transactions only and limited to
     * what the user may see (record scope per module, amount field rules).
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    private function capitalWidgets(array $user): ?array
    {
        $gate = App::gate();
        $da = App::dataAccess();
        $out = [];
        if ($gate->allows($user, 'partners.view')) {
            $out['partners'] = (new \App\Services\Partners\PartnerService(App::db()))->counts($da->constrain($user, 'partners', 'p'));
        }
        $money = $gate->allows($user, 'capital_ledger.view') && App::fields()->access($user, 'capital_ledger', 'running_balance')->showsRealValue();
        if ($money) {
            $sums = App::capitalLedger()->summaries($da->constrainAs($user, 'capital_ledger', 'partners', 'p'));
            $names = [];
            if ($sums !== []) {
                foreach (App::db()->select('SELECT id, partner_code, name FROM partners WHERE id IN (' . implode(',', array_map('intval', array_keys($sums))) . ')') as $p) {
                    $names[(int) $p['id']] = $p['name'];
                }
            }
            $t = ['contributions' => 0, 'withdrawals' => 0, 'closing' => 0, 'net_contributed' => 0, 'net_withdrawn' => 0];
            $bars = [];
            foreach ($sums as $id => $s) {
                foreach ($t as $k => $_) {
                    $t[$k] += $s[$k];
                }
                if ($s['closing'] !== 0) {
                    $bars[] = ['label' => $names[$id] ?? ('#' . $id), 'paise' => $s['closing']];
                }
            }
            usort($bars, static fn (array $a, array $b): int => $b['paise'] <=> $a['paise']);
            if (count($bars) > 10) {
                $rest = array_slice($bars, 9);
                $bars = array_slice($bars, 0, 9);
                $bars[] = ['label' => 'Other partners (' . count($rest) . ')', 'paise' => array_sum(array_column($rest, 'paise'))];
            }
            $out['totals'] = $t;
            $out['bars'] = $bars;
            $out['chart'] = $bars === [] ? null : ['labels' => array_column($bars, 'label'), 'values' => array_map(static fn (array $b): float => \App\Support\Money::toFloat($b['paise']), $bars)];
        }
        foreach (['contribution' => 'capital_contributions', 'withdrawal' => 'capital_withdrawals'] as $type => $module) {
            if (!$gate->allows($user, "{$module}.view")) {
                continue;
            }
            $totals = (new \App\Services\Partners\CapitalService(App::db()))->totals($type, [], $da->constrain($user, $module, 't'));
            $pending = ['count' => ($totals['pending_approval']['count'] ?? 0) + ($totals['approved']['count'] ?? 0), 'amount' => ($totals['pending_approval']['amount'] ?? 0) + ($totals['approved']['amount'] ?? 0)];
            $pending['showAmount'] = App::fields()->access($user, $module, 'amount')->showsRealValue();
            $out['pending_' . $type] = $pending;
        }
        return $out === [] ? null : $out;
    }
}
