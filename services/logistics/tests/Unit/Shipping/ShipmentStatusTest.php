<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ShipmentStatusTest extends TestCase
{
    /** @return iterable<string, array{ShipmentStatus, list<ShipmentStatus>}> */
    public static function table(): iterable
    {
        yield 'created' => [ShipmentStatus::Created, [ShipmentStatus::ReadyForPickup, ShipmentStatus::Cancelled]];
        yield 'ready_for_pickup' => [ShipmentStatus::ReadyForPickup, [ShipmentStatus::PickedUp, ShipmentStatus::Cancelled]];
        yield 'picked_up' => [ShipmentStatus::PickedUp, [ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery]];
        yield 'in_transit' => [ShipmentStatus::InTransit, [ShipmentStatus::InTransit, ShipmentStatus::OutForDelivery]];
        yield 'out_for_delivery' => [ShipmentStatus::OutForDelivery, [ShipmentStatus::Delivered, ShipmentStatus::DeliveryFailed]];
        yield 'delivery_failed' => [ShipmentStatus::DeliveryFailed, [ShipmentStatus::OutForDelivery, ShipmentStatus::Returning]];
        yield 'returning' => [ShipmentStatus::Returning, [ShipmentStatus::Returned]];
        yield 'delivered' => [ShipmentStatus::Delivered, []];
        yield 'returned' => [ShipmentStatus::Returned, []];
        yield 'cancelled' => [ShipmentStatus::Cancelled, []];
    }

    /** @param list<ShipmentStatus> $allowed */
    #[Test]
    #[DataProvider('table')]
    public function it_follows_the_documented_transition_table(ShipmentStatus $from, array $allowed): void
    {
        foreach (ShipmentStatus::cases() as $target) {
            self::assertSame(in_array($target, $allowed, true), $from->canMoveTo($target), sprintf('%s -> %s', $from->value, $target->value));
        }
        self::assertSame($allowed === [], $from->isFinal());
    }

    #[Test]
    public function the_table_above_names_every_status(): void
    {
        $covered = array_map(static fn(array $row): ShipmentStatus => $row[0], iterator_to_array(self::table(), false));

        self::assertEqualsCanonicalizing(ShipmentStatus::cases(), $covered);
    }

    #[Test]
    public function nothing_leads_back_to_created(): void
    {
        foreach (ShipmentStatus::cases() as $status) {
            self::assertFalse($status->canMoveTo(ShipmentStatus::Created), $status->value);
        }
    }
}
