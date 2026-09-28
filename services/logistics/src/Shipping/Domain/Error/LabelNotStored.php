<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Error;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The label was printed but not stored; the job tries again, and the shipment waits in created. */
final class LabelNotStored extends DomainError
{
    public static function because(string $reason, ?Throwable $cause = null): self
    {
        return new self(sprintf('The label could not be stored: %s.', $reason), previous: $cause);
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
