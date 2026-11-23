<?php

declare(strict_types=1);

namespace App\Core\Schema;

use App\Core\Database;

final class Schema
{
    private Grammar $grammar;
    /** @var list<string> SQL collected in pretend mode */
    public array $queries = [];

    /**
     * @param string|null $driver  compile for another driver (e.g. preview MySQL DDL)
     * @param bool        $pretend collect SQL in $queries instead of executing it
     */
    public function __construct(private readonly Database $db, ?string $driver = null, private readonly bool $pretend = false)
    {
        $this->grammar = new Grammar($driver ?? $db->driver());
    }

    /** @param callable(Blueprint): void $definition */
    public function create(string $table, callable $definition): void
    {
        $bp = new Blueprint($table);
        $definition($bp);
        foreach ($this->grammar->compileCreate($bp) as $sql) {
            $this->run($sql);
        }
    }

    public function drop(string $table): void
    {
        $this->run($this->grammar->compileDrop($table));
    }

    private function run(string $sql): void
    {
        if ($this->pretend) {
            $this->queries[] = $sql;
            return;
        }
        $this->db->pdo()->exec($sql);
    }

    public function hasTable(string $table): bool
    {
        if ($this->db->driver() === 'sqlite') {
            return (bool) $this->db->scalar("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        }
        return (bool) $this->db->scalar(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
    }
}
