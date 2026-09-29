<?php

declare(strict_types=1);

namespace Tracking\Delivery\Domain;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** Where the news of the deliveries is kept is out of reach: a report is refused for now, not lost silently. */
final class DeliveriesUnavailable extends DomainError
{
    public static function because(Throwable $cause): self
    {
        return new self('The live deliveries are out of reach right now.', 0, $cause);
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
