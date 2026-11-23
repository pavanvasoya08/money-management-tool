<?php

declare(strict_types=1);

namespace App\Controllers\Partners;

final class AdjustmentController extends CapitalController
{
    protected function type(): string
    {
        return 'adjustment';
    }

    protected function routePrefix(): string
    {
        return 'capital.adjustments';
    }

    protected function names(): array
    {
        return ['Capital adjustment', 'Capital adjustments'];
    }
}
