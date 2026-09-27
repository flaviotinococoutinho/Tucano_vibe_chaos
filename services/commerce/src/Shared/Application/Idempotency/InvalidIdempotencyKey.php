<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Idempotency;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class InvalidIdempotencyKey extends DomainError
{
    public function category(): ErrorCategory
    {
        return ErrorCategory::InvalidInput;
    }
}
