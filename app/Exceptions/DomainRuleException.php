<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A business rule was violated (e.g. "cannot deactivate the last Super Admin").
 * The message is safe to show to the user.
 */
final class DomainRuleException extends RuntimeException
{
}
