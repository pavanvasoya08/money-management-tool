<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

final class ContributionController extends CapitalController
{
    protected function type(): string
    {
        return 'contribution';
    }

    protected function routePrefix(): string
    {
        return 'capital.contributions';
    }

    protected function names(): array
    {
        return ['Capital contribution', 'Capital contributions'];
    }
}
