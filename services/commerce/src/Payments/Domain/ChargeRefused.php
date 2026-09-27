<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The provider refused the request itself (not the card): bad input, or the same key with another card. */
final class ChargeRefused extends DomainError
{
    public static function because(string $reason): self
    {
        return new self(sprintf('The payment provider refused the charge: %s', $reason));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Conflict;
    }
}
