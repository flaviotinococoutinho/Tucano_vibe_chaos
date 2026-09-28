<?php

declare(strict_types=1);

namespace Logistics\Timeline\Domain;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The public pages are out of reach right now: nothing to show, and the page says when to come back. */
final class TrackingPagesUnavailable extends DomainError
{
    public private(set) int $retryAfterSeconds = 1;

    public static function forSeconds(int $seconds, ?Throwable $cause = null): self
    {
        $error = new self(sprintf('The tracking pages are out of reach; try again in %d s.', $seconds), 0, $cause);
        $error->retryAfterSeconds = max(1, $seconds);

        return $error;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
