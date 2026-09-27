<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Stringable;

/** The carrier that takes a shipment, such as tucano-express (VARCHAR(32)). */
final readonly class CarrierCode implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^[a-z0-9-]{1,32}$/', $value) !== 1) {
            throw InvalidShipment::because(sprintf('"%s" is not a carrier code.', $value));
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
