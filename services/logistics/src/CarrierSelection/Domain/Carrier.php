<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain;

/** A row of the carriers table: who they are, and the heaviest consignment they take. */
final readonly class Carrier
{
    public function __construct(
        public string $code,
        public CarrierKind $kind,
        public int $maxWeightGrams,
    ) {}

    public function carries(Consignment $consignment): bool
    {
        return $consignment->weightGrams <= $this->maxWeightGrams;
    }

    public function is(string $code): bool
    {
        return $this->code === $code;
    }
}
