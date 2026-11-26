<?php

declare(strict_types=1);

namespace App\Services\Ecommerce;

use App\Core\Database;

/**
 * E-commerce dashboard figures from the platform master only. There are no sales, orders or revenue
 * here (Phase 9); seller accounts arrive with Phase 8B; connection figures appear only once an
 * integration (Phase 8C) has written connection data.
 */
final class PlatformDashboardService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $f @return array<string, mixed> */
    public function data(array $user, array $f): array
    {
        $svc = new PlatformService($this->db);
        $rows = $svc->list($user, array_merge($f, ['archived' => 'with']));
        $t = ['total' => 0, 'active' => 0, 'inactive' => 0, 'under_configuration' => 0, 'archived' => 0, 'connected' => 0, 'disconnected' => 0, 'needs_configuration' => 0];
        $ids = [];
        foreach ($rows as $r) {
            $t['total']++;
            $t[$r['status']]++;
            if ($r['connection_status'] === 'connected') {
                $t['connected']++;
            } elseif (in_array($r['connection_status'], ['disconnected', 'error'], true)) {
                $t['disconnected']++;
            }
            if ($r['status'] !== 'archived' && ($r['status'] === 'under_configuration' || !$r['readiness']['complete'])) {
                $t['needs_configuration']++;
            }
            $ids[] = (int) $r['id'];
        }
        $recent = $rows;
        usort($recent, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']) ?: $b['id'] <=> $a['id']);
        $summary = [];
        if ($ids !== []) {
            $in = implode(',', $ids);
            $counts = [];
            foreach (['gst' => 'ecommerce_platform_gst_mappings', 'activities' => 'ecommerce_platform_business_mappings', 'partners' => 'ecommerce_platform_partner_mappings',
                'warehouses' => 'ecommerce_platform_warehouse_mappings'] as $k => $table) {
                foreach ($this->db->select("SELECT platform_id, COUNT(*) AS n FROM {$table} WHERE status = 'active' AND platform_id IN ({$in}) GROUP BY platform_id") as $c) {
                    $counts[(int) $c['platform_id']][$k] = (int) $c['n'];
                }
            }
            foreach ($this->db->select("SELECT platform_id, COUNT(*) AS n FROM ecommerce_platform_documents WHERE status = 'active' AND platform_id IN ({$in}) GROUP BY platform_id") as $c) {
                $counts[(int) $c['platform_id']]['documents'] = (int) $c['n'];
            }
            foreach ($rows as $r) {
                if ($r['status'] === 'archived' && ($f['status'] ?? '') !== 'archived') {
                    continue;
                }
                $summary[] = $r + ['counts' => ($counts[(int) $r['id']] ?? []) + ['gst' => 0, 'activities' => 0, 'partners' => 0, 'warehouses' => 0, 'documents' => 0]];
            }
        }
        $hw = $ids === [] ? '1 = 0' : 'h.platform_id IN (' . implode(',', $ids) . ')';
        $hp = [];
        if (($f['from'] ?? '') !== '') {
            $hw .= ' AND h.created_at >= ?';
            $hp[] = $f['from'] . ' 00:00:00';
        }
        if (($f['to'] ?? '') !== '') {
            $hw .= ' AND h.created_at <= ?';
            $hp[] = $f['to'] . ' 23:59:59';
        }
        $byCategory = [];
        foreach ($rows as $r) {
            if ($r['status'] !== 'archived' || ($f['status'] ?? '') === 'archived') {
                $byCategory[$r['category_name']] = ($byCategory[$r['category_name']] ?? 0) + 1;
            }
        }
        arsort($byCategory);
        return ['totals' => $t, 'recent' => array_slice($recent, 0, 6), 'summary' => $summary, 'byCategory' => $byCategory,
            'activity' => PlatformHistory::read($this->db, $hw, $hp, 10), 'hasIntegrationData' => $t['connected'] + $t['disconnected'] > 0];
    }
}
