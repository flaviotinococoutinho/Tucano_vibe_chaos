<?php

declare(strict_types=1);

namespace Commerce\Inventory\Domain;

/** Where stock lives. A center in the destination state ships faster and cheaper, so it is tried first. */
final readonly class FulfillmentCenters
{
    /** @var list<FulfillmentCenter> */
    private array $centers;

    public function __construct(FulfillmentCenter ...$centers)
    {
        $this->centers = array_values($centers);
    }

    /** @return list<FulfillmentCenter> */
    public function sameStateFirst(string $state): array
    {
        $centers = $this->centers;
        usort($centers, static fn(FulfillmentCenter $a, FulfillmentCenter $b): int => [$a->state !== $state, $a->code] <=> [$b->state !== $state, $b->code]);

        return $centers;
    }
}
