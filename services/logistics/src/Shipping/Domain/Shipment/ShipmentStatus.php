<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

/**
 * The shipment lifecycle as one field, following the transition table of
 * docs/architecture/state-machines.md. The match is exhaustive: a new case
 * without its transitions fails static analysis and throws UnhandledMatchError
 * at runtime. The table says whether a move exists; the guards say whether the
 * data at hand allows it.
 */
enum ShipmentStatus: string
{
    case Created = 'created';
    case ReadyForPickup = 'ready_for_pickup';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case Returning = 'returning';
    case Returned = 'returned';
    case Cancelled = 'cancelled';

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->next(), true);
    }

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Created => [self::ReadyForPickup, self::Cancelled],
            self::ReadyForPickup => [self::PickedUp, self::Cancelled],
            self::PickedUp => [self::InTransit, self::OutForDelivery],
            self::InTransit => [self::InTransit, self::OutForDelivery],
            self::OutForDelivery => [self::Delivered, self::DeliveryFailed],
            self::DeliveryFailed => [self::OutForDelivery, self::Returning],
            self::Returning => [self::Returned],
            self::Delivered, self::Returned, self::Cancelled => [],
        };
    }

    public function isFinal(): bool
    {
        return $this->next() === [];
    }

    /** The parcels left the last hub for the door: a hub scan from now on is old news, not a step. */
    public function isPastTheHubs(): bool
    {
        return in_array($this, [self::OutForDelivery, self::DeliveryFailed, self::Delivered, self::Returning, self::Returned], true);
    }

    /** From here on the carrier moves it: what comes next is an event of the carrier, by webhook or from its history. */
    public function awaitsCarrier(): bool
    {
        return $this !== self::Created && !$this->isFinal();
    }
}
