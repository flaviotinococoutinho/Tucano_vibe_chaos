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

    /** @return iterable<string, array{ShipmentBuilder}> */
    public static function stores(): iterable
    {
        yield 'a shipment of a store' => [ShipmentBuilder::aShipment()->inStore('bemtevi')];
        yield 'a shipment from before the stores' => [ShipmentBuilder::aShipment()->withoutStore()];
    }

    #[Test]
    #[DataProvider('stores')]
    public function every_event_of_the_machine_follows_its_contract_with_the_store_or_without_it(ShipmentBuilder $builder): void
    {
        // The door, the way back and the cancellation reach every status, so every event type goes out.
        $events = [
            ...$builder->in(ShipmentStatus::Delivered)->releaseEvents(),
            ...$builder->in(ShipmentStatus::Returned)->releaseEvents(),
            ...$builder->in(ShipmentStatus::Cancelled)->releaseEvents(),
        ];

        $checked = [];
        foreach ($events as $event) {
            $envelope = self::envelopeOf($event);
            $status = substr($event->eventType(), strlen('tucano.logistics.shipment.'));
            self::assertMatchesContract('cloudevent.schema.json', $envelope);
            self::assertMatchesContract('logistics.shipment.' . $status . '.schema.json', $envelope->data);
            $checked[$status] = true;
        }
        self::assertCount(count(ShipmentStatus::cases()), $checked, 'One event type per status of the machine.');
    }

    #[Test]
    public function the_contracts_keep_out_what_consumers_must_not_rely_on(): void
    {
        $payload = ShipmentBuilder::aShipment()->create()->releaseEvents()[0]->payload();
        $withTheThoroughfare = [...$payload, 'destination' => [...$payload['destination'], 'thoroughfare' => ['type' => 'Avenida', 'name' => 'Paulista']]];
        $downToTheNeighborhood = [...$payload, 'destination' => [...$payload['destination'], 'divisions' => [...$payload['destination']['divisions'], ['kind' => 'neighborhood', 'code' => null, 'name' => 'Bela Vista']]]];
        // A shipment without a store leaves the field out, because the contracts have no null for it.
        $withANullStore = [...$payload, 'store' => null];
        $withAStoreOutsideTheSlug = [...$payload, 'store' => 'Sabiá Casa e Esporte'];
        $cancelledAfterPickup = [
            'shipmentId' => $payload['shipmentId'],
            'trackingCode' => $payload['trackingCode'],
            'orderId' => $payload['orderId'],
            'previousStatus' => 'picked_up',
            'reason' => 'order_cancelled',
        ];

        self::assertMatchesContract('logistics.shipment.created.schema.json', self::asJson($payload));
        self::assertBreaksContract('logistics.shipment.created.schema.json', self::asJson($withTheThoroughfare));
        self::assertBreaksContract('logistics.shipment.created.schema.json', self::asJson($downToTheNeighborhood));
        self::assertBreaksContract('logistics.shipment.created.schema.json', self::asJson($withANullStore));
        self::assertBreaksContract('logistics.shipment.created.schema.json', self::asJson($withAStoreOutsideTheSlug));
        self::assertBreaksContract('logistics.shipment.cancelled.schema.json', self::asJson($cancelledAfterPickup));
    }

    private static function envelopeOf(DomainEvent $event): stdClass
    {
        $envelope = json_decode(CloudEvent::fromDomainEvent($event, '/logistics', 'req-42#3', OrderEvents::PAID_EVENT)->toJson(), flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $envelope);

        return $envelope;
    }
}
