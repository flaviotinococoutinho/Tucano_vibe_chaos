<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class UnknownFulfillmentCenter extends DomainError
{
    public static function withCode(string $code): self
    {
        return new self(sprintf('Fulfillment center %s is not in the fulfillment_centers table.', $code));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
