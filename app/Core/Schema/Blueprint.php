<?php

declare(strict_types=1);

namespace App\Core\Schema;

/**
 * Fluent table definition used by migrations. Compiled to SQL by a Grammar
 * (MySQL 8 for production, SQLite for the automated test suite).
 */
final class Blueprint
{
    /** @var list<Column> */
    public array $columns = [];
    /** @var list<array{type: string, columns: list<string>, name: string}> */
    public array $indexes = [];
    /** @var list<array{column: string, on: string, references: string, onDelete: string, onUpdate: string, name: string}> */
    public array $foreignKeys = [];
    public ?string $comment = null;

    public function __construct(public readonly string $table)
    {
    }

    private function add(string $type, string $name, array $options = []): Column
    {
        $column = new Column($type, $name, $options);
        $this->columns[] = $column;
        return $column;
    }

    public function id(string $name = 'id'): Column
    {
        return $this->add('bigIncrements', $name);
    }

    public function smallId(string $name = 'id'): Column
    {
        return $this->add('increments', $name);
    }

    public function string(string $name, int $length = 191): Column
    {
        return $this->add('string', $name, ['length' => $length]);
    }

    public function char(string $name, int $length): Column
    {
        return $this->add('char', $name, ['length' => $length]);
    }

    public function text(string $name): Column
    {
        return $this->add('text', $name);
    }

    public function integer(string $name): Column
    {
        return $this->add('integer', $name);
    }

    public function unsignedInteger(string $name): Column
    {
        return $this->add('unsignedInteger', $name);
    }

    public function unsignedBigInteger(string $name): Column
    {
        return $this->add('unsignedBigInteger', $name);
    }

    public function boolean(string $name): Column
    {
        return $this->add('boolean', $name);
    }

    /** @param list<string> $allowed */
    public function enum(string $name, array $allowed): Column
    {
        return $this->add('enum', $name, ['allowed' => $allowed]);
    }

    /** Exact numeric (money). MySQL DECIMAL(p,s); SQLite NUMERIC. */
    public function decimal(string $name, int $precision = 15, int $scale = 2): Column
    {
        return $this->add('decimal', $name, ['precision' => $precision, 'scale' => $scale]);
    }

    public function json(string $name): Column
    {
        return $this->add('json', $name);
    }

    public function dateTime(string $name): Column
    {
        return $this->add('dateTime', $name);
    }

    /** created_at + updated_at (UTC, set by the application). */
    public function timestamps(): void
    {
        $this->dateTime('created_at');
        $this->dateTime('updated_at');
    }

    /** Foreign key column (BIGINT UNSIGNED) with a constraint. */
    public function foreignId(string $column, string $on, string $references = 'id', string $onDelete = 'RESTRICT', bool $big = true): Column
    {
        $col = $big ? $this->unsignedBigInteger($column) : $this->unsignedInteger($column);
        $this->foreignKeys[] = [
            'column' => $column, 'on' => $on, 'references' => $references,
            'onDelete' => strtoupper($onDelete), 'onUpdate' => 'CASCADE',
            'name' => "fk_{$this->table}_{$column}",
        ];
        return $col;
    }

    /** @param string|list<string> $columns */
    public function unique(string|array $columns, ?string $name = null): void
    {
        $columns = (array) $columns;
        $this->indexes[] = ['type' => 'unique', 'columns' => $columns, 'name' => $name ?? 'uq_' . $this->table . '_' . implode('_', $columns)];
    }

    /** @param string|list<string> $columns */
    public function index(string|array $columns, ?string $name = null): void
    {
        $columns = (array) $columns;
        $this->indexes[] = ['type' => 'index', 'columns' => $columns, 'name' => $name ?? 'ix_' . $this->table . '_' . implode('_', $columns)];
    }

    public function comment(string $comment): void
    {
        $this->comment = $comment;
    }
}
