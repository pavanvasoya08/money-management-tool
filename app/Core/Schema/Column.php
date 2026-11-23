<?php

declare(strict_types=1);

namespace App\Core\Schema;

final class Column
{
    public bool $nullable = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public ?string $comment = null;

    /** @param array<string, mixed> $options */
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly array $options = [],
    ) {
    }

    public function nullable(bool $value = true): self
    {
        $this->nullable = $value;
        return $this;
    }

    public function default(mixed $value): self
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    public function comment(string $comment): self
    {
        $this->comment = $comment;
        return $this;
    }
}
