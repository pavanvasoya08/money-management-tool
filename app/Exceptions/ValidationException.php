<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(public readonly array $errors, public readonly ?string $redirectTo = null)
    {
        parent::__construct('The given data was invalid.');
    }

    public static function withMessage(string $field, string $message, ?string $redirectTo = null): self
    {
        return new self([$field => [$message]], $redirectTo);
    }
}
