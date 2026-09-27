<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class OrderNotFound extends DomainError
{
    public static function withId(string $orderId): self
    {
        return new self(sprintf('Order %s does not exist.', $orderId));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
