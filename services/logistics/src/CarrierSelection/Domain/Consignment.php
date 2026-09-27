<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain;

/** What a carrier has to take: from which state, to which state, and how heavy it is. */
final readonly class Consignment
{
    public function __construct(
        public string $originState,
        public string $destinationState,
        public int $weightGrams,
    ) {}

    public function staysInState(): bool
    {
        return $this->originState === $this->destinationState;
    }
}
