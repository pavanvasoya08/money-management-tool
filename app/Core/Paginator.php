<?php

declare(strict_types=1);

namespace App\Core;

final class Paginator
{
    public readonly int $lastPage;

    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
        $this->lastPage = max(1, (int) ceil($total / max(1, $perPage)));
    }

    /**
     * @param string $baseSql   "FROM ... WHERE ..." part (no SELECT / ORDER / LIMIT)
     * @param array<int|string, mixed> $params
     */
    public static function query(Database $db, string $select, string $baseSql, string $orderBy, array $params, int $page, int $perPage): self
    {
        $perPage = max(1, min(100, $perPage));
        $total = (int) $db->scalar('SELECT COUNT(*) ' . $baseSql, $params);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $items = $db->select("SELECT {$select} {$baseSql} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}", $params);
        return new self($items, $total, $page, $perPage);
    }

    public function from(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function to(): int
    {
        return min($this->total, $this->page * $this->perPage);
    }
}
