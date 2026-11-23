<?php

declare(strict_types=1);

namespace App\Exceptions;

final class AuthorizationException extends HttpException
{
    public function __construct(public readonly ?string $permission = null, string $message = 'This action is unauthorized.')
    {
        parent::__construct(403, $message);
    }
}
