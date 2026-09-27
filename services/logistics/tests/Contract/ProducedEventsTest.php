<?php

declare(strict_types=1);

namespace Tests\Contract;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Shipment\CancellationReason;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Tests\AssertsContracts;
use Tests\Builders\ShipmentBuilder;
use Tests\Fixtures\OrderEvents;
use Tucano\SharedKernel\Domain\DomainEvent;
use Tucano\SharedKernel\Messaging\CloudEvent;

/**
 * The events Logistics publishes, in the envelope that goes to Kafka, against
 * contracts/events/logistics.shipment.*. The integration tests check the same
 * contracts on what actually lands in the outbox.
 */
final class ProducedEventsTest extends TestCase
{
    use AssertsContracts;

    #[Test]
    public function shipment_created_follows_its_contract(): void
    {
        $shipment = ShipmentBuilder::aShipment()
            ->withParcels(ShipmentBuilder::parcel(2200, 240, 170, 80), ShipmentBuilder::parcel(350, 120, 90, 100))
            ->create();

        $envelope = self::envelopeOf($shipment->releaseEvents()[0]);

        self::assertMatchesContract('cloudevent.schema.json', $envelope);
        self::assertMatchesContract('logistics.shipment.created.schema.json', $envelope->data);
    }

    /** @return iterable<string, array{ShipmentStatus}> */
    public static function cancellable(): iterable
    {
        yield 'from created' => [ShipmentStatus::Created];
        yield 'from ready_for_pickup' => [ShipmentStatus::ReadyForPickup];
    }

    #[Test]
    #[DataProvider('cancellable')]
    public function shipment_cancelled_follows_its_contract(ShipmentStatus $from): void
    {
        $shipment = ShipmentBuilder::aShipment()->in($from);
        $shipment->releaseEvents();
        $shipment->cancel(CancellationReason::OrderCancelled, new DateTimeImmutable('2026-09-27T13:00:00Z'));

        $envelope = self::envelopeOf($shipment->releaseEvents()[0]);

        self::assertMatchesContract('cloudevent.schema.json', $envelope);
        self::assertMatchesContract('logistics.shipment.cancelled.schema.json', $envelope->data);
    }

    #[Test]
    public function the_contracts_keep_out_what_consumers_must_not_rely_on(): void
    {
        $payload = ShipmentBuilder::aShipment()->create()->releaseEvents()[0]->payload();
        $withTheStreet = [...$payload, 'destination' => ['city' => 'São Paulo', 'state' => 'SP', 'postalCode' => '01310100', 'street' => 'Avenida Paulista']];
        $cancelledAfterPickup = [
            'shipmentId' => $payload['shipmentId'],
            'trackingCode' => $payload['trackingCode'],
            'orderId' => $payload['orderId'],
            'previousStatus' => 'picked_up',
            'reason' => 'order_cancelled',
        ];

        self::assertMatchesContract('logistics.shipment.created.schema.json', self::asJson($payload));
        self::assertBreaksContract('logistics.shipment.created.schema.json', self::asJson($withTheStreet));
        self::assertBreaksContract('logistics.shipment.cancelled.schema.json', self::asJson($cancelledAfterPickup));
    }

    private static function envelopeOf(DomainEvent $event): stdClass
    {
        $envelope = json_decode(CloudEvent::fromDomainEvent($event, '/logistics', 'req-42#3', OrderEvents::PAID_EVENT)->toJson(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $envelope);

        return $envelope;
    }
}
