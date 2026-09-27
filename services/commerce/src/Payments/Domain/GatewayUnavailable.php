<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

/** The provider is known to be down (the circuit is open): nothing is sent until the wait is over. */
final class GatewayUnavailable extends DomainError
{
    public private(set) int $retryAfterSeconds = 1;

    public static function forSeconds(int $seconds): self
    {
        $error = new self(sprintf('The payment provider is unavailable; try again in %d s.', $seconds));
        $error->retryAfterSeconds = max(1, $seconds);

        return $error;
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::Unavailable;
    }
}
