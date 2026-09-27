<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Stringable;

/** Fixed 4-character warehouse code (CHAR(4)), such as GRU1: where the parcels leave from. */
final readonly class FulfillmentCenterCode implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[A-Z]{3}\d$/', $value) !== 1) {
            throw InvalidShipment::because(sprintf('"%s" is not a fulfillment center code.', $value));
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
