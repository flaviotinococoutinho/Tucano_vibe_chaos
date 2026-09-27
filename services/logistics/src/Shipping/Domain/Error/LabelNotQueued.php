<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The label queue did not take the request: every request would fail the same way until it is back. */
final class LabelNotQueued extends DomainError
{
    public static function because(string $reason, ?Throwable $cause = null): self
    {
        return new self(sprintf('The label request did not reach the queue: %s.', $reason), previous: $cause);
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
