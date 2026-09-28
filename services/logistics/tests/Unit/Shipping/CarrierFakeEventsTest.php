<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use DateTimeImmutable;
use InvalidArgumentException;
use Logistics\Shipping\Adapter\CarrierFakeEvents;
use Logistics\Shipping\Application\CarrierEvent;
use Logistics\Shipping\Application\CarrierReport;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Messaging\EventFields;

final class CarrierFakeEventsTest extends TestCase
{
    private const string TRACKING_CODE = 'TX02PWW6JFR5G00';

    /** @return iterable<string, array{string, array<string, int|string>, CarrierEvent}> */
    public static function events(): iterable
    {
        $report = CarrierReport::of('evt_1', TrackingCode::fromString(self::TRACKING_CODE), new DateTimeImmutable('2026-09-27T15:00:00.000Z'));

        yield 'a pickup' => ['parcel.picked_up', [], CarrierEvent::pickedUp($report)];
        yield 'a hub scan' => ['parcel.hub_scanned', ['hub' => 'Hub Cajamar (SP)'], CarrierEvent::hubScanned($report, Hub::named('Hub Cajamar (SP)'))];
        yield 'out for delivery' => ['parcel.out_for_delivery', ['attempt' => 1], CarrierEvent::outForDelivery($report)];
        yield 'a delivery' => ['parcel.delivered', ['attempt' => 1, 'receiverName' => 'Carlos Lima', 'receiverDocument' => '***.456.789-**'], CarrierEvent::delivered($report, ProofOfDelivery::of('Carlos Lima', '***.456.789-**'))];
        yield 'a failed visit' => ['parcel.delivery_failed', ['attempt' => 2, 'reason' => 'address_not_found'], CarrierEvent::deliveryFailed($report, DeliveryFailure::AddressNotFound)];
        yield 'the way back' => ['parcel.returning', [], CarrierEvent::returning($report)];
        yield 'back home' => ['parcel.returned', [], CarrierEvent::returned($report)];
    }

    /** @param array<string, int|string> $details */
    #[Test]
    #[DataProvider('events')]
    public function each_parcel_event_becomes_its_step(string $type, array $details, CarrierEvent $expected): void
    {
        self::assertEquals($expected, CarrierFakeEvents::toCarrierEvent(self::event($type, $details)));
    }

    #[Test]
    public function an_event_shipping_does_not_know_is_left_alone(): void
    {
        self::assertNull(CarrierFakeEvents::toCarrierEvent(self::event('parcel.lost_in_space')));
    }

    #[Test]
    public function a_known_event_without_what_it_needs_is_unreadable(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CarrierFakeEvents::toCarrierEvent(self::event('parcel.hub_scanned'));
    }

    /** @param array<string, int|string> $details */
    private static function event(string $type, array $details = []): EventFields
    {
        return new EventFields([
            'id' => 'evt_1',
            'type' => $type,
            'createdAt' => '2026-09-27T15:00:00.000Z',
            'data' => ['pickupId' => 'pk_01M3HBNT8TE1R8SV13STYN9CXP', 'carrier' => 'correio-nacional', 'reference' => '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d', 'trackingCode' => self::TRACKING_CODE, ...$details],
        ]);
    }
}
