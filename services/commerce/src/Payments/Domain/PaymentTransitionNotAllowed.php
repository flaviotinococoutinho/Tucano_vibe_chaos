<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class PaymentTransitionNotAllowed extends DomainError
{
    public static function from(PaymentStatus $current, PaymentStatus $target): self
    {
        return new self(sprintf('A %s payment cannot become %s.', $current->value, $target->value));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
