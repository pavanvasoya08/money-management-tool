<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

final class WithdrawalController extends CapitalController
{
    protected function type(): string
    {
        return 'withdrawal';
    }

    protected function routePrefix(): string
    {
        return 'capital.withdrawals';
    }

    protected function names(): array
    {
        return ['Capital withdrawal', 'Capital withdrawals'];
    }
}
