<?php

declare(strict_types=1);

namespace Tests\Builders;

use Closure;
use DateTimeImmutable;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Parcel;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Shipment\CancellationReason;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Recipient;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\Hub;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\BrazilianState;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/**
 * Test data builder: sensible defaults, change only what the test is about.
 * in() walks the documented machine, one hour per step, until the shipment
 * reaches the status the test starts from.
 */
final class ShipmentBuilder
{
    /** Tracking codes are UNIQUE in the database, so every shipment built gets the next sequence. */
    private static int $sequence = 0;

    private OrderId $orderId;

    private DateTimeImmutable $createdAt;

    private Parcels $parcels;

    private function __construct()
    {
        $this->orderId = OrderId::generate();
        $this->createdAt = new DateTimeImmutable('2026-09-27T12:00:00Z');
        $this->parcels = Parcels::of(self::parcel(1100, 240, 170, 40));
    }

    public static function aShipment(): self
    {
        return new self();
    }

    public static function parcel(int $grams, int $lengthMm, int $widthMm, int $heightMm): Parcel
    {
        return Parcel::of(Weight::ofGrams($grams), Dimensions::ofMillimetres($lengthMm, $widthMm, $heightMm));
    }

    public static function destination(): Address
    {
        return Address::builder()
            ->thoroughfare('Avenida', 'Paulista')->number('1000')->complement('Apto 12')
            ->state(BrazilianState::SP)->municipality('São Paulo', '3550308')->neighborhood('Bela Vista')
            ->postalCode('01310-100')->coordinates(-23.561414, -46.655881)
            ->build();
    }

    public function forOrder(OrderId $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    public function createdAt(string $instant): self
    {
        $this->createdAt = new DateTimeImmutable($instant);

        return $this;
    }

    public function withParcels(Parcel ...$parcels): self
    {
        $this->parcels = Parcels::of(...$parcels);

        return $this;
    }

    public function create(): Shipment
    {
        return Shipment::create(
            ShipmentReference::of(
                ShipmentId::generate(),
                TrackingCode::fromSnowflake(Snowflake::compose($this->createdAt->getTimestamp() * 1000, new NodeId(1, 12), self::$sequence++ % (Snowflake::MAX_SEQUENCE + 1))),
                $this->orderId,
            ),
            CarrierCode::of('tucano-express'),
            FulfillmentCenterCode::of('GRU1'),
            Recipient::of('Ana Souza', 'ana@example.com'),
            self::destination(),
            $this->parcels,
            $this->createdAt,
        );
    }

    public function in(ShipmentStatus $status): Shipment
    {
        $shipment = $this->create();
        $at = $this->createdAt;
        foreach (self::pathTo($status) as $step) {
            $at = $at->modify('+1 hour');
            $step($shipment, $at);
        }

        return $shipment;
    }

    /** A shipment whose recipient refused it at the first visit. */
    public function refused(): Shipment
    {
        $shipment = $this->in(ShipmentStatus::OutForDelivery);
        $shipment->recordFailedAttempt(DeliveryFailure::RecipientRefused, $this->createdAt->modify('+1 day'));

        return $shipment;
    }

    /** @return list<Closure(Shipment, DateTimeImmutable): void> */
    private static function pathTo(ShipmentStatus $status): array
    {
        $labelled = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->markReadyForPickup(ShippingLabel::storedAt('labels/2026/09/27/label.pdf'), $at);
        $pickedUp = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordPickup($at);
        $scanned = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordHubScan(Hub::named('Hub Cajamar'), $at);
        $outForDelivery = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->sendOutForDelivery($at);
        $delivered = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordDelivery(ProofOfDelivery::of('Ana Souza', '12345678900'), $at);
        $absent = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordFailedAttempt(DeliveryFailure::RecipientAbsent, $at);
        $refused = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordFailedAttempt(DeliveryFailure::RecipientRefused, $at);
        $returning = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->returnToSender($at);
        $returned = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->recordReturn($at);
        $cancelled = static fn(Shipment $shipment, DateTimeImmutable $at) => $shipment->cancel(CancellationReason::OrderCancelled, $at);
        $toTheDoor = [$labelled, $pickedUp, $scanned, $outForDelivery];

        return match ($status) {
            ShipmentStatus::Created => [],
            ShipmentStatus::ReadyForPickup => [$labelled],
            ShipmentStatus::PickedUp => [$labelled, $pickedUp],
            ShipmentStatus::InTransit => [$labelled, $pickedUp, $scanned],
            ShipmentStatus::OutForDelivery => $toTheDoor,
            ShipmentStatus::Delivered => [...$toTheDoor, $delivered],
            ShipmentStatus::DeliveryFailed => [...$toTheDoor, $absent],
            ShipmentStatus::Returning => [...$toTheDoor, $refused, $returning],
            ShipmentStatus::Returned => [...$toTheDoor, $refused, $returning, $returned],
            ShipmentStatus::Cancelled => [$cancelled],
        };
    }
}
