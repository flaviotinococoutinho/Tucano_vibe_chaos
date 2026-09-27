<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class OrderChangedMeanwhile extends DomainError
{
    public static function withId(string $orderId, int $expectedVersion): self
    {
        return new self(sprintf('Order %s is no longer at version %d; someone changed it meanwhile.', $orderId, $expectedVersion));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
