<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Parcel;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Traversable;

/**
 * First-class collection: a shipment has at least one parcel, and the total
 * weight the carriers care about is computed here, in one place.
 *
 * @implements IteratorAggregate<int, Parcel>
 */
final readonly class Parcels implements IteratorAggregate, Countable
{
    /** @param non-empty-list<Parcel> $parcels */
    private function __construct(private array $parcels) {}

    public static function of(Parcel ...$parcels): self
    {
        if ($parcels === []) {
            throw InvalidShipment::because('A shipment needs at least one parcel.');
        }

        return new self(array_values($parcels));
    }

    public function totalWeight(): Weight
    {
        return array_reduce(
            array_slice($this->parcels, 1),
            static fn(Weight $total, Parcel $parcel): Weight => $total->plus($parcel->weight),
            $this->parcels[0]->weight,
        );
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->parcels);
    }

    public function count(): int
    {
        return count($this->parcels);
    }
}
