<?php

declare(strict_types=1);

namespace App\Exceptions;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/**
 * A value the catalog refuses after the format checks passed, like a category or a store that
 * does not exist. The problem lists it under its field, the same way a validation error does,
 * so the client learns which field to fix.
 */
abstract class InvalidField extends DomainError
{
    /** The field of the request that carried the value. */
    abstract public function field(): string;

    public function category(): ErrorCategory
    {
        return ErrorCategory::InvalidInput;
    }
}
