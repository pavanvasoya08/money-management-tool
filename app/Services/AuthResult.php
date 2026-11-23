<?php

declare(strict_types=1);

namespace App\Services;

final class AuthResult
{
    private function __construct(
        public readonly bool $success,
        public readonly ?string $message = null,
        public readonly ?string $rememberCookie = null,
    ) {
    }

    public static function ok(?string $rememberCookie = null): self
    {
        return new self(true, null, $rememberCookie);
    }

    public static function fail(string $message): self
    {
        return new self(false, $message);
    }
}
