<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;
use Tucano\SharedKernel\Domain\ProblemType;

#[ProblemType('order-not-payable')]
final class OrderNotPayable extends DomainError
{
    public static function because(string $orderId, string $reason): self
    {
        return new self(sprintf('Order %s cannot be paid: %s.', $orderId, $reason));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
