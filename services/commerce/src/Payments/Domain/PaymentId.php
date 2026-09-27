<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain;

use Tucano\SharedKernel\Identity\UuidIdentifier;

/** Also the Idempotency-Key sent to the provider: a retry of the same payment can never charge twice. */
final readonly class PaymentId extends UuidIdentifier {}
