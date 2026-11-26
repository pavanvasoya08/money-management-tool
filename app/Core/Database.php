<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Thin, safe PDO wrapper. Every query uses prepared statements; table and
 * column identifiers are validated before being interpolated.
 *
 * Supported drivers: mysql (production) and sqlite (automated tests only).
 */
final class Database
{
    private ?PDO $pdo = null;
    private int $transactionLevel = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    private function connect(): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];

        try {
            if ($this->driver() === 'sqlite') {
                $path = (string) $this->config['database'];
                $pdo = new PDO('sqlite:' . $path, null, null, $options);
                $pdo->exec('PRAGMA foreign_keys = ON');
                $pdo->exec('PRAGMA busy_timeout = 5000');
                return $pdo;
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $this->config['host'],
                (int) $this->config['port'],
                $this->config['database']
            );
            $pdo = new PDO($dsn, (string) $this->config['username'], (string) $this->config['password'], $options);
            // Driver-specific PDO::MYSQL_ATTR_* constants are deprecated in PHP 8.5, so session
            // settings are applied with plain statements instead.
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
            return $pdo;
        } catch (\PDOException $e) {
            // Never leak DSN/credentials in the message shown to users.
            throw new RuntimeException('Database connection failed. Check DB_* settings in .env.', 0, $e);
        }
    }

    /** @param array<int|string, mixed> $params */
    public function statement(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $name = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_INT,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue($name, is_bool($value) ? (int) $value : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /** @return list<array<string, mixed>> */
    public function select(string $sql, array $params = []): array
    {
        return $this->statement($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->statement($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $params = []): mixed
    {
        $value = $this->statement($sql, $params)->fetchColumn();
        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->statement($sql, $params)->rowCount();
    }

    /** @param array<string, mixed> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->identifier($table),
            implode(', ', array_map($this->identifier(...), $columns)),
            implode(', ', array_map(static fn (string $c): string => ':' . $c, $columns))
        );
        $this->statement($sql, $data);
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where column => value (AND-ed equality)
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($where === []) {
            throw new RuntimeException('Refusing to UPDATE without a WHERE clause.');
        }
        $set = [];
        $params = [];
        foreach ($data as $column => $value) {
            $set[] = $this->identifier($column) . ' = :set_' . $column;
            $params['set_' . $column] = $value;
        }
        [$whereSql, $whereParams] = $this->whereClause($where);
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->identifier($table), implode(', ', $set), $whereSql);
        return $this->execute($sql, $params + $whereParams);
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new RuntimeException('Refusing to DELETE without a WHERE clause.');
        }
        [$whereSql, $params] = $this->whereClause($where);
        return $this->execute(sprintf('DELETE FROM %s WHERE %s', $this->identifier($table), $whereSql), $params);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function whereClause(array $where): array
    {
        $parts = [];
        $params = [];
        foreach ($where as $column => $value) {
            if ($value === null) {
                $parts[] = $this->identifier($column) . ' IS NULL';
                continue;
            }
            $parts[] = $this->identifier($column) . ' = :w_' . $column;
            $params['w_' . $column] = $value;
        }
        return [implode(' AND ', $parts), $params];
    }

    /**
     * Run a callback inside a transaction. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(Database): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();
        if ($this->transactionLevel === 0) {
            $pdo->beginTransaction();
        }
        $this->transactionLevel++;
        try {
            $result = $callback($this);
            $this->transactionLevel--;
            if ($this->transactionLevel === 0) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            $this->transactionLevel--;
            if ($this->transactionLevel === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Whether a transaction() callback is running (stock posting must happen inside one). */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /** Validate and quote an identifier (table/column). Prevents identifier injection. */
    public function identifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new RuntimeException('Invalid SQL identifier.');
        }
        return $this->driver() === 'mysql' ? "`{$name}`" : "\"{$name}\"";
    }

    /**
     * Escape LIKE wildcards in user search input. Use together with "ESCAPE '!'" in SQL
     * ('!' behaves identically in MySQL and SQLite, unlike backslash).
     */
    public static function likeEscape(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** Build a "%term%" LIKE parameter from raw user input. */
    public static function likeContains(string $value): string
    {
        return '%' . self::likeEscape($value) . '%';
    }
}
