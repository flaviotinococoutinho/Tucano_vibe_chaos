<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Domain;

use DomainException;

/**
 * Base for errors raised by the domain. Subclasses are named after the problem
 * (InsufficientStock, TransitionNotAllowed) and say which category they are,
 * never which HTTP status to use.
 */
abstract class DomainError extends DomainException
{
    abstract public function category(): ErrorCategory;
}
