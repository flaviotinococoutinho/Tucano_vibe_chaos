<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Error;

use Commerce\Ordering\Domain\Order\OrderStatus;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class OrderTransitionNotAllowed extends DomainError
{
    public static function from(OrderStatus $current, OrderStatus $target): self
    {
        return new self(sprintf('An order cannot go from %s to %s.', $current->value, $target->value));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
