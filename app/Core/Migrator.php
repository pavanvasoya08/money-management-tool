<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Schema\Blueprint;
use App\Core\Schema\Schema;
use RuntimeException;

/**
 * Runs migration files from database/migrations in filename order and records
 * them in the `migrations` table, grouped into batches for rollback.
 */
final class Migrator
{
    private Schema $schema;

    /** @var callable(string): void */
    private $output;

    public function __construct(private readonly Database $db, private readonly string $path, ?callable $output = null)
    {
        $this->schema = new Schema($db);
        $this->output = $output ?? static function (string $line): void {
        };
    }

    public function ensureRepository(): void
    {
        if ($this->schema->hasTable('migrations')) {
            return;
        }
        $this->schema->create('migrations', static function (Blueprint $t): void {
            $t->id();
            $t->string('migration', 191);
            $t->integer('batch');
            $t->dateTime('ran_at');
            $t->unique('migration');
        });
    }

    /** @return list<string> migration names (file basenames without .php) */
    public function files(): array
    {
        $files = glob(rtrim($this->path, '/') . '/*.php') ?: [];
        sort($files, SORT_STRING);
        return array_map(static fn (string $f): string => basename($f, '.php'), $files);
    }

    /** @return list<string> */
    public function ran(): array
    {
        $this->ensureRepository();
        return array_column($this->db->select('SELECT migration FROM migrations ORDER BY id'), 'migration');
    }

    /** @return list<string> names that were migrated */
    public function migrate(): array
    {
        $this->ensureRepository();
        $pending = array_values(array_diff($this->files(), $this->ran()));
        if ($pending === []) {
            ($this->output)('Nothing to migrate.');
            return [];
        }
        $batch = (int) $this->db->scalar('SELECT COALESCE(MAX(batch), 0) FROM migrations') + 1;
        foreach ($pending as $name) {
            ($this->output)("Migrating: {$name}");
            $this->resolve($name)->up($this->schema, $this->db);
            $this->db->insert('migrations', ['migration' => $name, 'batch' => $batch, 'ran_at' => now()]);
            ($this->output)("Migrated:  {$name}");
        }
        return $pending;
    }

    /** @return list<string> names that were rolled back */
    public function rollback(int $steps = 1): array
    {
        $this->ensureRepository();
        $batches = array_column(
            $this->db->select('SELECT DISTINCT batch FROM migrations ORDER BY batch DESC LIMIT ' . max(1, $steps)),
            'batch'
        );
        if ($batches === []) {
            ($this->output)('Nothing to roll back.');
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($batches), '?'));
        $rows = $this->db->select("SELECT migration FROM migrations WHERE batch IN ({$placeholders}) ORDER BY id DESC", $batches);
        $done = [];
        foreach ($rows as $row) {
            $name = $row['migration'];
            ($this->output)("Rolling back: {$name}");
            $this->resolve($name)->down($this->schema, $this->db);
            $this->db->delete('migrations', ['migration' => $name]);
            $done[] = $name;
        }
        return $done;
    }

    /** Drop everything (in reverse order) and migrate again. */
    public function fresh(): array
    {
        $this->ensureRepository();
        while ($this->ran() !== []) {
            $this->rollback(PHP_INT_MAX >> 32);
        }
        return $this->migrate();
    }

    /**
     * Compile pending (or all) migrations to SQL for a driver without executing them.
     * @return array<string, list<string>>
     */
    public function pretend(string $driver = 'mysql', bool $all = false): array
    {
        $names = $all ? $this->files() : array_values(array_diff($this->files(), $this->ran()));
        $out = [];
        foreach ($names as $name) {
            $schema = new Schema($this->db, $driver, true);
            $this->resolve($name)->up($schema, $this->db);
            $out[$name] = $schema->queries;
        }
        return $out;
    }

    /** @return list<array{migration: string, ran: bool}> */
    public function status(): array
    {
        $ran = $this->ran();
        return array_map(static fn (string $f): array => ['migration' => $f, 'ran' => in_array($f, $ran, true)], $this->files());
    }

    private function resolve(string $name): Migration
    {
        $file = rtrim($this->path, '/') . '/' . $name . '.php';
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new RuntimeException("Migration {$name} must return an instance of " . Migration::class);
        }
        return $migration;
    }
}
