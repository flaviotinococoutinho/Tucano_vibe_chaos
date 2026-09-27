<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class PaymentNotFound extends DomainError
{
    public static function withId(string $paymentId): self
    {
        return new self(sprintf('Payment %s does not exist.', $paymentId));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::NotFound;
    }
}
