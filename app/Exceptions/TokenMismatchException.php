<?php

declare(strict_types=1);

namespace App\Exceptions;

final class TokenMismatchException extends HttpException
{
    public function __construct()
    {
        parent::__construct(419, 'CSRF token mismatch.');
    }
}
