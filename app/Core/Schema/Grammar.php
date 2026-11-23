<?php

declare(strict_types=1);

namespace App\Core\Schema;

use InvalidArgumentException;

/**
 * Compiles a Blueprint into CREATE TABLE statements.
 *  - mysql : InnoDB, utf8mb4_unicode_ci, real FK constraints, indexes (production)
 *  - sqlite: equivalent structure for the automated test suite
 */
final class Grammar
{
    public function __construct(private readonly string $driver)
    {
        if (!in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new InvalidArgumentException("Unsupported schema driver [{$driver}].");
        }
    }

    /** @return list<string> */
    public function compileCreate(Blueprint $bp): array
    {
        $lines = [];
        foreach ($bp->columns as $column) {
            $lines[] = $this->columnSql($column);
        }

        if ($this->driver === 'mysql') {
            foreach ($bp->indexes as $index) {
                $cols = implode(', ', array_map($this->wrap(...), $index['columns']));
                $lines[] = ($index['type'] === 'unique' ? 'UNIQUE KEY ' : 'KEY ') . $this->wrap($index['name']) . " ({$cols})";
            }
        }
        foreach ($bp->foreignKeys as $fk) {
            $lines[] = sprintf(
                'CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
                $this->wrap($fk['name']), $this->wrap($fk['column']), $this->wrap($fk['on']),
                $this->wrap($fk['references']), $fk['onDelete'], $fk['onUpdate']
            );
        }

        $sql = 'CREATE TABLE ' . $this->wrap($bp->table) . " (\n    " . implode(",\n    ", $lines) . "\n)";
        if ($this->driver === 'mysql') {
            $sql .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
            if ($bp->comment !== null) {
                $sql .= ' COMMENT=' . $this->quote($bp->comment);
            }
        }

        $statements = [$sql];
        if ($this->driver === 'sqlite') {
            foreach ($bp->indexes as $index) {
                $cols = implode(', ', array_map($this->wrap(...), $index['columns']));
                $statements[] = 'CREATE ' . ($index['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX '
                    . $this->wrap($index['name']) . ' ON ' . $this->wrap($bp->table) . " ({$cols})";
            }
        }
        return $statements;
    }

    /**
     * ALTER TABLE for an existing table: add columns, indexes and (MySQL) foreign keys.
     * SQLite cannot add constraints to existing tables, so added FK columns are plain
     * columns there (the automated tests run on SQLite; production MySQL enforces FKs).
     *
     * @return list<string>
     */
    public function compileAlter(Blueprint $bp): array
    {
        $table = $this->wrap($bp->table);
        $statements = [];
        foreach ($bp->columns as $column) {
            $statements[] = "ALTER TABLE {$table} ADD COLUMN " . $this->columnSql($column);
        }
        foreach ($bp->indexes as $index) {
            $cols = implode(', ', array_map($this->wrap(...), $index['columns']));
            $statements[] = 'CREATE ' . ($index['type'] === 'unique' ? 'UNIQUE ' : '') . 'INDEX '
                . $this->wrap($index['name']) . " ON {$table} ({$cols})";
        }
        if ($this->driver === 'mysql') {
            foreach ($bp->foreignKeys as $fk) {
                $statements[] = sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
                    $table, $this->wrap($fk['name']), $this->wrap($fk['column']), $this->wrap($fk['on']),
                    $this->wrap($fk['references']), $fk['onDelete'], $fk['onUpdate']
                );
            }
        }
        return $statements;
    }

    /**
     * Reverse of compileAlter: drop FKs (MySQL), indexes, then columns.
     * @param list<string> $columns @param list<string> $foreignKeys @param list<string> $indexes
     * @return list<string>
     */
    public function compileDropColumns(string $table, array $columns, array $foreignKeys = [], array $indexes = []): array
    {
        $t = $this->wrap($table);
        $out = [];
        if ($this->driver === 'mysql') {
            foreach ($foreignKeys as $fk) {
                $out[] = "ALTER TABLE {$t} DROP FOREIGN KEY " . $this->wrap($fk);
            }
            foreach ($indexes as $ix) {
                $out[] = "ALTER TABLE {$t} DROP INDEX " . $this->wrap($ix);
            }
        } else {
            foreach ($indexes as $ix) {
                $out[] = 'DROP INDEX IF EXISTS ' . $this->wrap($ix);
            }
        }
        foreach ($columns as $c) {
            $out[] = "ALTER TABLE {$t} DROP COLUMN " . $this->wrap($c);
        }
        return $out;
    }

    public function compileDrop(string $table): string
    {
        return 'DROP TABLE IF EXISTS ' . $this->wrap($table);
    }

    private function columnSql(Column $c): string
    {
        $mysql = $this->driver === 'mysql';
        $name = $this->wrap($c->name);

        if ($c->type === 'bigIncrements' || $c->type === 'increments') {
            if (!$mysql) {
                return "{$name} INTEGER PRIMARY KEY AUTOINCREMENT";
            }
            return $name . ($c->type === 'bigIncrements' ? ' BIGINT UNSIGNED' : ' INT UNSIGNED') . ' NOT NULL AUTO_INCREMENT PRIMARY KEY';
        }

        $type = match ($c->type) {
            'string' => $mysql ? 'VARCHAR(' . (int) $c->options['length'] . ')' : 'VARCHAR(' . (int) $c->options['length'] . ')',
            'char' => 'CHAR(' . (int) $c->options['length'] . ')',
            'text' => $mysql ? 'TEXT' : 'TEXT',
            'integer' => $mysql ? 'INT' : 'INTEGER',
            'unsignedInteger' => $mysql ? 'INT UNSIGNED' : 'INTEGER',
            'unsignedBigInteger' => $mysql ? 'BIGINT UNSIGNED' : 'INTEGER',
            'boolean' => $mysql ? 'TINYINT(1)' : 'INTEGER',
            'enum' => $mysql
                ? 'ENUM(' . implode(', ', array_map($this->quote(...), $c->options['allowed'])) . ')'
                : 'VARCHAR(32)',
            'json' => $mysql ? 'JSON' : 'TEXT',
            'decimal' => $mysql ? 'DECIMAL(' . (int) $c->options['precision'] . ',' . (int) $c->options['scale'] . ')' : 'NUMERIC',
            'dateTime' => $mysql ? 'DATETIME' : 'DATETIME',
            'date' => $mysql ? 'DATE' : 'DATE',
            default => throw new InvalidArgumentException("Unknown column type [{$c->type}]."),
        };

        $sql = "{$name} {$type}" . ($c->nullable ? ' NULL' : ' NOT NULL');
        if ($c->hasDefault) {
            $sql .= ' DEFAULT ' . $this->defaultValue($c->default);
        }
        if (!$mysql && $c->type === 'enum') {
            $sql .= ' CHECK (' . $name . ' IN (' . implode(', ', array_map($this->quote(...), $c->options['allowed'])) . '))';
        }
        if ($mysql && $c->comment !== null) {
            $sql .= ' COMMENT ' . $this->quote($c->comment);
        }
        return $sql;
    }

    private function defaultValue(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => $this->quote((string) $value),
        };
    }

    public function wrap(string $identifier): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $identifier)) {
            throw new InvalidArgumentException('Invalid identifier in schema definition.');
        }
        return $this->driver === 'mysql' ? "`{$identifier}`" : "\"{$identifier}\"";
    }

    private function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
