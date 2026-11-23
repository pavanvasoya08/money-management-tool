<?php

declare(strict_types=1);

namespace App\Services\Partners;

use App\Core\App;
use App\Core\Database;
use App\Exceptions\AuthorizationException;
use App\Exceptions\HttpException;
use App\Services\Authorization\FieldPolicy;
use App\Support\BusinessDate;
use App\Support\Money;
use App\Support\Percent;

/**
 * The eight Phase 3 partner reports. Each report:
 *  - needs partner_reports.view plus view on its source module (export: both .export),
 *  - loads only records inside the user's record scope for that module (SQL),
 *  - drops columns whose field is hidden for the user and masks masked ones,
 * so the page, CSV, Excel and PDF always contain the same authorised data.
 */
final class PartnerReportService
{
    /** key => [title, source module, description] */
    public const REPORTS = [
        'partners' => ['Partner list', 'partners', 'All partners with contact details and status.'],
        'contributions' => ['Capital contributions', 'capital_contributions', 'Every contribution with status, method and reference.'],
        'withdrawals' => ['Capital withdrawals', 'capital_withdrawals', 'Every withdrawal with status, method and reason.'],
        'balances' => ['Capital balances', 'capital_ledger', 'Opening, movements and closing capital per partner (posted entries only).'],
        'ledger' => ['Partner-wise capital ledger', 'capital_ledger', 'Debit/credit lines with running balance for one partner.'],
        'profit_sharing' => ['Profit-sharing configuration', 'profit_sharing', 'Arrangements, effective periods and partner percentages.'],
        'pending_contributions' => ['Pending contributions', 'capital_contributions', 'Contributions awaiting approval or posting.'],
        'pending_withdrawals' => ['Pending withdrawals', 'capital_withdrawals', 'Withdrawals awaiting approval or posting.'],
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /** Reports this user may open. @param array<string, mixed> $user @return array<string, array{0: string, 1: string, 2: string}> */
    public function available(array $user): array
    {
        if (!App::gate()->allows($user, 'partner_reports.view')) {
            return [];
        }
        return array_filter(self::REPORTS, static fn (array $r): bool => App::gate()->allows($user, $r[1] . '.view'));
    }

    /** @param array<string, mixed> $user */
    public function authorize(array $user, string $key, bool $export = false): void
    {
        if (!isset(self::REPORTS[$key])) {
            throw new HttpException(404, 'Report not found.');
        }
        $module = self::REPORTS[$key][1];
        foreach ($export ? ['partner_reports.export', "{$module}.export"] : ['partner_reports.view', "{$module}.view"] as $perm) {
            if (!App::gate()->allows($user, $perm)) {
                throw new AuthorizationException($perm, 'You are not allowed to ' . ($export ? 'export' : 'view') . ' this report.');
            }
        }
    }

    /**
     * @param array<string, mixed> $user @param array<string, mixed> $filters
     * @return array{title: string, columns: array<string, array{label: string, type?: string}>, rows: list<array<string, mixed>>, totals: ?array<string, mixed>, meta: list<string>, notice: ?string}
     */
    public function run(array $user, string $key, array $filters): array
    {
        $this->authorize($user, $key);
        return $this->runUnchecked($user, $key, $filters);
    }

    /**
     * Build a report without the partner_reports permission check — for module
     * pages that have their own permission (e.g. partners.export). Record and
     * field rules still apply.
     * @param array<string, mixed> $user @param array<string, mixed> $filters
     * @return array{title: string, columns: array<string, array{label: string, type?: string}>, rows: list<array<string, mixed>>, totals: ?array<string, mixed>, meta: list<string>, notice: ?string}
     */
    public function runUnchecked(array $user, string $key, array $filters): array
    {
        if (!isset(self::REPORTS[$key])) {
            throw new HttpException(404, 'Report not found.');
        }
        [$title, $module] = self::REPORTS[$key];
        $out = match ($key) {
            'partners' => $this->partners($user, $filters),
            'contributions' => $this->capital($user, 'contribution', $filters),
            'withdrawals' => $this->capital($user, 'withdrawal', $filters),
            'pending_contributions' => $this->capital($user, 'contribution', ['status' => 'pending'] + $filters),
            'pending_withdrawals' => $this->capital($user, 'withdrawal', ['status' => 'pending'] + $filters),
            'balances' => $this->balances($user, $filters),
            'ledger' => $this->ledger($user, $filters),
            'profit_sharing' => $this->profitSharing($user, $filters),
        };
        $out += ['totals' => null, 'notice' => null];
        // Field-level rules: hidden columns disappear, masked columns are masked (also in totals).
        $fields = App::fields();
        foreach ($out['columns'] as $col => $def) {
            if (!isset($def['field'])) {
                continue;
            }
            [$fm, $ff] = $def['field'];
            $access = $fields->access($user, $fm, $ff);
            if (!$access->canView()) {
                unset($out['columns'][$col]);
                continue;
            }
            if (!$access->showsRealValue()) {
                $mask = $fields->maskType($fm, $ff);
                foreach ($out['rows'] as &$r) {
                    if (($r[$col] ?? null) !== null && $r[$col] !== '') {
                        $r[$col] = FieldPolicy::mask(is_int($r[$col]) && ($def['type'] ?? '') === 'money' ? Money::toDb($r[$col]) : (string) $r[$col], $mask);
                    }
                }
                unset($r);
                if ($out['totals'] !== null && isset($out['totals'][$col]) && $out['totals'][$col] !== '') {
                    $out['totals'][$col] = FieldPolicy::mask('0', $mask);
                }
            }
        }
        $out['title'] = $title;
        $out['meta'] = array_merge([App::dataAccess()->resolve($user, $module)->describe() . ' · generated by ' . $user['name']], $this->describeFilters($filters));
        return $out;
    }

    /** @param array<string, mixed> $filters @return list<string> */
    private function describeFilters(array $filters): array
    {
        $parts = [];
        if (!empty($filters['partner'])) {
            $p = $this->db->first('SELECT partner_code, name FROM partners WHERE id = ?', [(int) $filters['partner']]);
            if ($p) {
                $parts[] = "Partner: {$p['partner_code']} {$p['name']}";
            }
        }
        if (!empty($filters['status'])) {
            $parts[] = 'Status: ' . str_replace('_', ' ', (string) $filters['status']);
        }
        if (!empty($filters['from']) || !empty($filters['to'])) {
            $parts[] = 'Period: ' . (!empty($filters['from']) ? BusinessDate::format((string) $filters['from']) : 'start') . ' – ' . (!empty($filters['to']) ? BusinessDate::format((string) $filters['to']) : 'today');
        }
        if (!empty($filters['q'])) {
            $parts[] = 'Search: ' . $filters['q'];
        }
        return $parts === [] ? [] : [implode(' · ', $parts)];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $filters */
    private function partners(array $user, array $filters): array
    {
        $rows = (new PartnerService($this->db))->all(['q' => $filters['q'] ?? '', 'status' => $filters['status'] ?? ''], App::dataAccess()->constrain($user, 'partners', 'p'));
        $showMoney = App::gate()->allows($user, 'partners.view_financial_summary');
        $sum = $showMoney && $rows !== [] ? App::capitalLedger()->summaries(['p.id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ')', []]) : [];
        $columns = [
            'partner_code' => ['label' => 'Partner ID'], 'name' => ['label' => 'Name', 'field' => ['partners', 'name']],
            'status' => ['label' => 'Status'], 'email' => ['label' => 'Email', 'field' => ['partners', 'email']],
            'mobile' => ['label' => 'Mobile', 'field' => ['partners', 'mobile']], 'city' => ['label' => 'City'], 'state' => ['label' => 'State'],
            'pan_number' => ['label' => 'PAN', 'field' => ['partners', 'pan_number']], 'date_of_joining' => ['label' => 'Joined'],
            'user_name' => ['label' => 'Linked login'],
        ];
        if ($showMoney) {
            $columns += [
                'total_contributed' => ['label' => 'Contributed (net)', 'type' => 'money', 'field' => ['partners', 'total_contributed']],
                'total_withdrawn' => ['label' => 'Withdrawn (net)', 'type' => 'money', 'field' => ['partners', 'total_withdrawn']],
                'capital_balance' => ['label' => 'Capital balance', 'type' => 'money', 'field' => ['partners', 'capital_balance']],
            ];
        }
        $out = [];
        $totals = ['partner_code' => 'Total (' . count($rows) . ')', 'total_contributed' => 0, 'total_withdrawn' => 0, 'capital_balance' => 0];
        foreach ($rows as $r) {
            $s = $sum[(int) $r['id']] ?? null;
            $row = [
                'partner_code' => $r['partner_code'], 'name' => $r['name'], 'status' => ucfirst((string) $r['status']), 'email' => $r['email'], 'mobile' => $r['mobile'],
                'city' => $r['city'], 'state' => $r['state'], 'pan_number' => $r['pan_number'], 'date_of_joining' => BusinessDate::format($r['date_of_joining']),
                'user_name' => $r['user_name'] ?? '—',
            ];
            if ($s !== null) {
                $row += ['total_contributed' => $s['net_contributed'], 'total_withdrawn' => $s['net_withdrawn'], 'capital_balance' => $s['closing']];
                $totals['total_contributed'] += $s['net_contributed'];
                $totals['total_withdrawn'] += $s['net_withdrawn'];
                $totals['capital_balance'] += $s['closing'];
            }
            $out[] = $row;
        }
        return ['columns' => $columns, 'rows' => $out, 'totals' => $showMoney ? $totals : null];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $filters */
    private function capital(array $user, string $type, array $filters): array
    {
        $module = CapitalService::module($type);
        $rows = (new CapitalService($this->db))->all($type, $filters, App::dataAccess()->constrain($user, $module, 't'));
        $columns = [
            'transaction_no' => ['label' => 'Number'], 'transaction_date' => ['label' => 'Date'], 'partner' => ['label' => 'Partner'],
            'kind' => ['label' => 'Type'], 'amount' => ['label' => 'Amount', 'type' => 'money', 'field' => [$module, 'amount']],
            'payment_method' => ['label' => 'Method'], 'payment_reference' => ['label' => 'Reference', 'field' => [$module, 'payment_reference']],
            'payment_source_ref' => ['label' => $type === 'withdrawal' ? 'Paid to (bank/cash ref.)' : 'Received in (bank/cash ref.)', 'field' => [$module, 'payment_source_ref']],
        ];
        if ($type === 'withdrawal') {
            $columns['reason'] = ['label' => 'Reason'];
        }
        $columns += ['status' => ['label' => 'Status'], 'created_by_name' => ['label' => 'Created by'], 'approved_by_name' => ['label' => 'Approved by']];
        $out = [];
        $total = 0;
        foreach ($rows as $r) {
            $amt = Money::fromDb($r['amount']);
            $signed = $r['type'] === 'reversal' ? -$amt : $amt;
            $out[] = [
                'transaction_no' => $r['transaction_no'], 'transaction_date' => BusinessDate::format($r['transaction_date']), 'partner' => $r['partner_code'] . ' ' . $r['partner_name'],
                'kind' => $r['type'] === 'reversal' ? 'Reversal' : ucfirst($type), 'amount' => $signed,
                'payment_method' => CapitalService::METHODS[$r['payment_method']] ?? '—', 'payment_reference' => $r['payment_reference'], 'payment_source_ref' => $r['payment_source_ref'],
                'reason' => $r['reason'], 'status' => CapitalService::STATUSES[$r['status']] ?? $r['status'], 'created_by_name' => $r['created_by_name'], 'approved_by_name' => $r['approved_by_name'] ?? '—',
            ];
            $total += $signed;
        }
        return ['columns' => $columns, 'rows' => $out, 'totals' => ['transaction_no' => 'Total (' . count($out) . ')', 'amount' => $total],
            'notice' => ($filters['status'] ?? '') === 'pending' ? 'Pending entries do not affect capital balances until they are approved and posted.' : null];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $filters */
    private function balances(array $user, array $filters): array
    {
        [$where, $params] = App::dataAccess()->constrainAs($user, 'capital_ledger', 'partners', 'p');
        if (!empty($filters['partner'])) {
            $where .= ' AND p.id = ?';
            $params[] = (int) $filters['partner'];
        }
        if (($filters['status'] ?? '') !== '' && isset(PartnerService::STATUSES[$filters['status']])) {
            $where .= ' AND p.status = ?';
            $params[] = (string) $filters['status'];
        }
        $asOf = !empty($filters['to']) && \App\Core\Validator::isDate((string) $filters['to']) ? (string) $filters['to'] : null;
        $sums = App::capitalLedger()->summaries([$where, $params], $asOf);
        $names = [];
        foreach ($this->db->select("SELECT id, partner_code, name, status FROM partners p WHERE {$where} ORDER BY name", $params) as $p) {
            $names[(int) $p['id']] = $p;
        }
        $money = fn (string $label, string $field = 'amount'): array => ['label' => $label, 'type' => 'money', 'field' => ['capital_ledger', $field]];
        $columns = [
            'partner_code' => ['label' => 'Partner ID'], 'name' => ['label' => 'Partner'], 'status' => ['label' => 'Status'],
            'opening' => $money('Opening capital', 'running_balance'), 'contributions' => $money('Contributions'), 'adjustments' => $money('Adjustments'),
            'withdrawals' => $money('Withdrawals'), 'reversed' => $money('Reversed'), 'closing' => $money('Closing capital', 'running_balance'),
            'pending_in' => $money('Pending contributions'), 'pending_out' => $money('Pending withdrawals'),
        ];
        $rows = [];
        $t = array_fill_keys(['opening', 'contributions', 'adjustments', 'withdrawals', 'reversed', 'closing', 'pending_in', 'pending_out'], 0);
        foreach ($names as $id => $p) {
            $s = $sums[$id] ?? null;
            if ($s === null) {
                continue;
            }
            $row = ['partner_id' => $id, 'partner_code' => $p['partner_code'], 'name' => $p['name'], 'status' => ucfirst((string) $p['status']),
                'opening' => $s['opening'], 'contributions' => $s['contributions'], 'adjustments' => $s['adjustments'], 'withdrawals' => $s['withdrawals'],
                'reversed' => $s['reversed'], 'closing' => $s['closing'],
                'pending_in' => $s['pending_contributions'] + $s['unposted_contributions'], 'pending_out' => $s['pending_withdrawals'] + $s['unposted_withdrawals']];
            foreach ($t as $k => $_) {
                $t[$k] += $row[$k];
            }
            $rows[] = $row; // partner_id is not a column, so it is never exported
        }
        return ['columns' => $columns, 'rows' => $rows, 'totals' => ['partner_code' => 'Total (' . count($rows) . ')'] + $t,
            'notice' => 'Closing = Opening + Contributions + Adjustments − Withdrawals − Reversed. Posted entries only' . ($asOf ? ', as of ' . BusinessDate::format($asOf) : '') . '.'];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $filters */
    private function ledger(array $user, array $filters): array
    {
        $columns = [
            'transaction_date' => ['label' => 'Date'], 'transaction_no' => ['label' => 'Reference'], 'description' => ['label' => 'Particulars'],
            'payment_reference' => ['label' => 'Payment ref.', 'field' => ['capital_contributions', 'payment_reference']],
            'debit' => ['label' => 'Debit', 'type' => 'money', 'field' => ['capital_ledger', 'amount']],
            'credit' => ['label' => 'Credit', 'type' => 'money', 'field' => ['capital_ledger', 'amount']],
            'balance' => ['label' => 'Balance', 'type' => 'money', 'field' => ['capital_ledger', 'running_balance']],
        ];
        $partnerId = (int) ($filters['partner'] ?? 0);
        if ($partnerId === 0) {
            return ['columns' => $columns, 'rows' => [], 'notice' => 'Choose a partner to see their ledger.'];
        }
        [$where, $params] = App::dataAccess()->constrainAs($user, 'capital_ledger', 'partners', 'p');
        if (!$this->db->scalar("SELECT 1 FROM partners p WHERE p.id = ? AND {$where}", array_merge([$partnerId], $params))) {
            throw new HttpException(404, 'Partner not found.');
        }
        $from = !empty($filters['from']) && \App\Core\Validator::isDate((string) $filters['from']) ? (string) $filters['from'] : null;
        $to = !empty($filters['to']) && \App\Core\Validator::isDate((string) $filters['to']) ? (string) $filters['to'] : null;
        $l = App::capitalLedger()->entries($partnerId, $from, $to);
        $rows = [['transaction_date' => $from ? BusinessDate::format($from) : '', 'transaction_no' => '', 'description' => 'Opening balance', 'payment_reference' => '', 'debit' => '', 'credit' => '', 'balance' => $l['opening']]];
        foreach ($l['rows'] as $r) {
            $rows[] = ['transaction_date' => BusinessDate::format($r['transaction_date']), 'transaction_no' => $r['transaction_no'], 'description' => self::particulars($r),
                'payment_reference' => $r['payment_reference'], 'debit' => $r['debit'] ?: '', 'credit' => $r['credit'] ?: '', 'balance' => $r['running_balance']];
        }
        return ['columns' => $columns, 'rows' => $rows, 'totals' => ['description' => 'Closing balance', 'debit' => $l['debit'], 'credit' => $l['credit'], 'balance' => $l['closing']]];
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $filters */
    private function profitSharing(array $user, array $filters): array
    {
        $svc = new ProfitSharingService($this->db);
        $columns = [
            'arrangement' => ['label' => 'Arrangement'], 'method' => ['label' => 'Calculation method'], 'period' => ['label' => 'Effective period'],
            'state' => ['label' => 'State'], 'partner' => ['label' => 'Partner'], 'percentage' => ['label' => 'Profit share', 'field' => ['profit_sharing', 'percentage']],
        ];
        $rows = [];
        foreach ($svc->arrangements() as $a) {
            foreach ($svc->allocations((int) $a['id']) as $x) {
                foreach ($x['lines'] as $l) {
                    if (!empty($filters['partner']) && (int) $l['partner_id'] !== (int) $filters['partner']) {
                        continue;
                    }
                    $rows[] = ['arrangement' => $a['name'], 'method' => ProfitSharingService::METHODS[$a['calculation_method']] ?? $a['calculation_method'],
                        'period' => BusinessDate::format($x['effective_from']) . ' – ' . ($x['effective_to'] ? BusinessDate::format($x['effective_to']) : 'open'),
                        'state' => ucfirst((string) $x['state']), 'partner' => $l['partner_code'] . ' ' . $l['partner_name'], 'percentage' => Percent::format($l['units'])];
                }
            }
        }
        return ['columns' => $columns, 'rows' => $rows, 'notice' => 'Profit-sharing percentages are independent of capital. No profit is calculated or distributed in this module.'];
    }

    /** Human description of a ledger line. @param array<string, mixed> $r */
    public static function particulars(array $r): string
    {
        return match ($r['type']) {
            'contribution' => 'Capital contribution' . (isset(CapitalService::METHODS[$r['payment_method']]) ? ' (' . CapitalService::METHODS[$r['payment_method']] . ')' : ''),
            'withdrawal' => 'Capital withdrawal' . ($r['reason'] ? ' — ' . $r['reason'] : ''),
            'adjustment' => (CapitalService::CATEGORIES[$r['adjustment_category']] ?? 'Adjustment') . ($r['reason'] ? ' — ' . $r['reason'] : ''),
            'reversal' => 'Reversal of ' . ($r['reversal_of_no'] ?? 'entry') . ($r['reason'] ? ' — ' . $r['reason'] : ''),
            default => (string) $r['type'],
        };
    }
}
