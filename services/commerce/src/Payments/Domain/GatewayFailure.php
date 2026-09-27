<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Throwable;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The request left, but no usable answer came back: the charge may exist at the provider or not. */
final class GatewayFailure extends DomainError
{
    public static function because(string $reason, ?Throwable $cause = null): self
    {
        return new self(sprintf('The payment provider did not answer properly: %s.', $reason), previous: $cause);
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
