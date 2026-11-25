<?php

declare(strict_types=1);

namespace App\Services\Product;

use App\Core\Database;

/**
 * Figures for the product dashboard — all counted from the database, within the user's
 * product record scope and the chosen filters.
 */
final class ProductDashboardService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @param array<string, mixed> $f @param array{0: string, 1: list<mixed>} $scope @return array<string, mixed> */
    public function data(array $f, array $scope): array
    {
        $products = new ProductService($this->db);
        [$w, $p] = $products->filterSql(['archived' => ($f['status'] ?? '') === '' ? 'with' : ''] + $f, $scope);
        $count = fn (string $extra = '', array $params = []): int => (int) $this->db->scalar("SELECT COUNT(*) FROM products p WHERE {$w}" . ($extra !== '' ? " AND {$extra}" : ''), array_merge($p, $params));
        $status = [];
        foreach ($this->db->select("SELECT p.status, COUNT(*) AS n FROM products p WHERE {$w} GROUP BY p.status", $p) as $r) {
            $status[$r['status']] = (int) $r['n'];
        }
        $live = "p.status <> 'archived'";
        $missing = function (string $kind) use ($products, $f, $scope): int {
            [$mw, $mp] = $products->filterSql(['missing' => $kind] + $f, $scope);
            return (int) $this->db->scalar("SELECT COUNT(*) FROM products p WHERE {$mw}", $mp);
        };
        $byCategory = $this->db->select("SELECT COALESCE(c.name, '(No category)') AS name, c.id, COUNT(*) AS n FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE {$w} AND {$live} GROUP BY c.id, c.name ORDER BY n DESC LIMIT 8", $p);
        $recent = static fn (string $order) => "SELECT p.id, p.product_code, p.name, p.sku, p.status, p.created_at, p.updated_at, t.name AS type_name, u.name AS by_name
            FROM products p JOIN product_types t ON t.id = p.product_type_id LEFT JOIN users u ON u.id = p.updated_by WHERE {$w} ORDER BY {$order} LIMIT 6";
        return [
            'total' => array_sum($status), 'status' => $status,
            'categories' => (int) $this->db->scalar('SELECT COUNT(*) FROM product_categories WHERE is_archived = 0'),
            'brands' => (int) $this->db->scalar('SELECT COUNT(*) FROM product_brands WHERE is_archived = 0'),
            'variants' => (int) $this->db->scalar("SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id WHERE {$w} AND v.status <> 'archived'", $p),
            'no_sku' => $missing('sku'), 'no_hsn' => $missing('hsn'), 'no_tax' => $missing('tax'), 'no_supplier' => $missing('supplier'),
            'pending_changes' => (int) $this->db->scalar("SELECT COUNT(*) FROM product_change_requests c JOIN products p ON p.id = c.product_id WHERE c.status = 'pending' AND {$w}", $p)
                + (int) $this->db->scalar("SELECT COUNT(*) FROM product_prices x JOIN products p ON p.id = x.product_id WHERE x.status = 'pending_approval' AND {$w}", $p)
                + (int) $this->db->scalar("SELECT COUNT(*) FROM product_tax_configs x JOIN products p ON p.id = x.product_id WHERE x.status = 'pending_approval' AND {$w}", $p),
            'by_category' => $byCategory,
            'recent_added' => $this->db->select($recent('p.created_at DESC, p.id DESC'), $p),
            'recent_updated' => $this->db->select($recent('p.updated_at DESC, p.id DESC'), $p),
            'live' => $count($live),
        ];
    }
}
