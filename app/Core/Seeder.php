<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Seeders must be idempotent: running them twice must not duplicate data.
 */
abstract class Seeder
{
    /** @var callable(string): void */
    protected $output;

    public function __construct(protected readonly Database $db, ?callable $output = null)
    {
        $this->output = $output ?? static function (string $line): void {
        };
    }

    abstract public function run(): void;

    /** @param class-string<Seeder> $class */
    protected function call(string $class): void
    {
        ($this->output)('Seeding: ' . (new \ReflectionClass($class))->getShortName());
        (new $class($this->db, $this->output))->run();
    }
}
