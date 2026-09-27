<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Transition;

use Logistics\Shipping\Domain\Error\InvalidShipment;

/** Where the label of a shipment is stored: the object key in S3 (VARCHAR(200)). */
final readonly class ShippingLabel
{
    private const int MAX_LENGTH = 200;

    private function __construct(public string $objectKey) {}

    public static function storedAt(string $objectKey): self
    {
        if (trim($objectKey) === '' || strlen($objectKey) > self::MAX_LENGTH) {
            throw InvalidShipment::because(sprintf('A label object key takes 1 to %d characters.', self::MAX_LENGTH));
        }

        return new self($objectKey);
    }
}
