<?php

declare(strict_types=1);

namespace App\Services\Authorization;

/**
 * The effective record visibility of one user for one module.
 * $rules are OR-ed together (union of record sets).
 */
final class ResolvedScope
{
    /** @param list<array{scope: DataScope, ids: list<int>, source: string}> $rules */
    public function __construct(
        public readonly bool $all,
        public readonly array $rules,
        public readonly string $source,
    ) {
    }

    public static function all(string $source): self
    {
        return new self(true, [], $source);
    }

    public static function none(string $source): self
    {
        return new self(false, [], $source);
    }

    public function isNone(): bool
    {
        return !$this->all && $this->rules === [];
    }

    public function describe(): string
    {
        if ($this->all) {
            return DataScope::All->label();
        }
        if ($this->rules === []) {
            return DataScope::None->label();
        }
        return implode(' + ', array_unique(array_map(static function (array $r): string {
            $label = $r['scope']->label();
            return $r['ids'] !== [] ? $label . ' (' . count($r['ids']) . ')' : $label;
        }, $this->rules)));
    }
}
