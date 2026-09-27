<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application;

/** What another module sends to choose a carrier: plain values, so Carrier Selection owns its own model. */
final readonly class CarrierRequest
{
    public function __construct(
        public string $originCenter,
        public string $destinationState,
        public int $weightGrams,
    ) {}
}
