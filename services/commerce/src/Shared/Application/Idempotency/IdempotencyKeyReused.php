<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Idempotency;

use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class IdempotencyKeyReused extends DomainError
{
    public static function for(IdempotencyKey $key): self
    {
        return new self(sprintf('Idempotency key %s was already used for a different request.', $key->value));
    }

    public function category(): ErrorCategory
    {
        return ErrorCategory::InvalidInput;
    }
}
