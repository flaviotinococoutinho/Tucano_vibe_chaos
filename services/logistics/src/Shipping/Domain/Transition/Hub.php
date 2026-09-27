<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Transition;

use Logistics\Shipping\Domain\Error\InvalidShipment;

/** The sorting center that scanned a shipment; the history keeps it as the location (VARCHAR(120)). */
final readonly class Hub
{
    private const int MAX_LENGTH = 120;

    private function __construct(public string $name) {}

    public static function named(string $name): self
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::MAX_LENGTH) {
            throw InvalidShipment::because(sprintf('A hub name takes 1 to %d characters.', self::MAX_LENGTH));
        }

        return new self($name);
    }
}
