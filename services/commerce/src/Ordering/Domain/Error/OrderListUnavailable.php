<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The customer's order list is out of reach right now: nothing to show, and the answer says when to come back. */
final class OrderListUnavailable extends DomainError
{
    public private(set) int $retryAfterSeconds = 1;

    public static function forSeconds(int $seconds, ?Throwable $cause = null): self
    {
        $error = new self(sprintf('The order list is out of reach; try again in %d s.', $seconds), 0, $cause);
        $error->retryAfterSeconds = max(1, $seconds);

        return $error;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
