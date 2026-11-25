<?php

declare(strict_types=1);

namespace App\Services\Supplier;

use App\Core\Database;

/** Figures for the supplier dashboard, counted from the database within the user's supplier scope and filters. */
final class SupplierDashboardService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $f @param array{0: string, 1: list<mixed>} $scope @return array<string, mixed> */
    public function data(array $f, array $scope): array
    {
        $svc = new SupplierService($this->db);
        [$w, $p] = $svc->filterSql(['archived' => ($f['status'] ?? '') === '' ? 'with' : ''] + $f, $scope);
        $status = [];
        foreach ($this->db->select("SELECT s.status, COUNT(*) AS n FROM suppliers s WHERE {$w} GROUP BY s.status", $p) as $r) {
            $status[$r['status']] = (int) $r['n'];
        }
        $linked = "s.id IN (SELECT supplier_id FROM product_supplier_mappings WHERE status = 'active')";
        return [
            'total' => array_sum($status), 'status' => $status,
            'linked' => (int) $this->db->scalar("SELECT COUNT(*) FROM suppliers s WHERE {$w} AND s.status <> 'archived' AND {$linked}", $p),
            'unlinked' => (int) $this->db->scalar("SELECT COUNT(*) FROM suppliers s WHERE {$w} AND s.status <> 'archived' AND NOT {$linked}", $p),
            'mappings' => (int) $this->db->scalar("SELECT COUNT(*) FROM product_supplier_mappings m JOIN suppliers s ON s.id = m.supplier_id WHERE m.status = 'active' AND {$w}", $p),
            'pending_bank' => (int) $this->db->scalar("SELECT COUNT(*) FROM supplier_bank_details b JOIN suppliers s ON s.id = b.supplier_id WHERE b.status = 'pending_approval' AND {$w}", $p),
            'by_type' => $this->db->select("SELECT t.name, COUNT(*) AS n FROM suppliers s JOIN supplier_types t ON t.id = s.supplier_type_id WHERE {$w} AND s.status <> 'archived' GROUP BY t.id, t.name ORDER BY n DESC", $p),
            'by_state' => $this->db->select("SELECT COALESCE(st.name, CASE WHEN s.country <> 'IN' THEN 'Outside India' ELSE '(No state)' END) AS name, COUNT(*) AS n FROM suppliers s LEFT JOIN gst_states st ON st.id = s.gst_state_id
                WHERE {$w} AND s.status <> 'archived' GROUP BY 1 ORDER BY n DESC LIMIT 8", $p),
            'recent' => $this->db->select("SELECT s.id, s.supplier_code, s.name, s.status, s.gstin, s.created_at, t.name AS type_name,
                    (SELECT COUNT(DISTINCT m.product_id) FROM product_supplier_mappings m WHERE m.supplier_id = s.id AND m.status = 'active') AS products
                FROM suppliers s JOIN supplier_types t ON t.id = s.supplier_type_id WHERE {$w} ORDER BY s.created_at DESC, s.id DESC LIMIT 6", $p),
        ];
    }
}
