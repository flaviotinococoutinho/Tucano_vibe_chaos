<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;

/**
 * How many times a courier went to the address, and how the last failed visit
 * ended. Three visits at most; after the third failure, or after a refusal,
 * the shipment may go back to the sender.
 */
final readonly class DeliveryAttempts
{
    public const int LIMIT = 3;

    private function __construct(public int $made, public ?DeliveryFailure $lastFailure) {}

    public static function none(): self
    {
        return new self(0, null);
    }

    public static function restore(int $made, ?DeliveryFailure $lastFailure): self
    {
        if ($made < 0 || $made > self::LIMIT) {
            throw InvalidShipment::because(sprintf('A shipment has from 0 to %d delivery attempts, got %d.', self::LIMIT, $made));
        }

        return new self($made, $lastFailure);
    }

    public function succeeded(): self
    {
        return new self($this->made + 1, $this->lastFailure);
    }

    public function failed(?DeliveryFailure $failure): self
    {
        return new self($this->made + 1, $failure);
    }

    /** The number of the visit a courier is about to make. */
    public function next(): int
    {
        return $this->made + 1;
    }

    public function allowAnother(): bool
    {
        return $this->made < self::LIMIT;
    }

    public function allowReturn(): bool
    {
        return !$this->allowAnother() || $this->lastFailure?->justifiesReturn() === true;
    }
}
