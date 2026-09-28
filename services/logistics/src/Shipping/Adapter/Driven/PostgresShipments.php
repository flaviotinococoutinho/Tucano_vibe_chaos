<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Domain\Error\ShipmentChangedMeanwhile;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Parcel;
use Logistics\Shipping\Domain\Parcel\Parcels;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Shipment\CarrierCode;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempt;
use Logistics\Shipping\Domain\Shipment\DeliveryAttempts;
use Logistics\Shipping\Domain\Shipment\FulfillmentCenterCode;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\Recipient;
use Logistics\Shipping\Domain\Shipment\Shipment;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use Logistics\Shipping\Domain\Shipment\ShipmentReference;
use Logistics\Shipping\Domain\Shipment\ShipmentSnapshot;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Shipment\StatusTransition;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\Divisions;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/**
 * Maps the Shipment aggregate to shipments, parcels and the append-only
 * shipment_transitions. How the last failed visit ended lives only in the
 * history, so it is read back from there.
 */
final readonly class PostgresShipments implements ForStoringShipments
{
    private const string TIMESTAMP = 'Y-m-d\TH:i:s.uP';

    public function __construct(private ConnectionInterface $connection) {}

    public function add(Shipment $shipment): void
    {
        $snapshot = $shipment->toSnapshot();
        $transitions = $shipment->releaseTransitions();
        $destination = $snapshot->destination;

        $this->connection->table('shipments')->insert([
            'id' => $snapshot->reference->id->toString(),
            'tracking_code' => $snapshot->reference->trackingCode->snowflake->toInt(),
            'order_id' => $snapshot->reference->orderId->toString(),
            'status' => $snapshot->status->value,
            'carrier_code' => (string) $snapshot->carrier,
            'origin' => (string) $snapshot->origin,
            'recipient_name' => $snapshot->recipient->name,
            'recipient_email' => $snapshot->recipient->email,
            'dest_thoroughfare_type' => $destination->thoroughfare->type,
            'dest_thoroughfare_name' => $destination->thoroughfare->name,
            'dest_number' => $destination->number,
            'dest_complement' => $destination->complement,
            'dest_divisions' => $destination->divisions->toJson(),
            'dest_postal_code' => (string) $destination->postalCode,
            'dest_latitude' => $destination->coordinates?->latitude,
            'dest_longitude' => $destination->coordinates?->longitude,
            'total_weight_grams' => $snapshot->parcels->totalWeight()->grams(),
            'delivery_attempts' => $snapshot->attempts->made,
            'label_object_key' => $snapshot->label?->objectKey,
            'created_at' => $snapshot->createdAt->format(self::TIMESTAMP),
            'updated_at' => self::lastChange($transitions, $snapshot->createdAt),
            'version' => $snapshot->version,
        ]);
        $this->connection->table('parcels')->insert(self::parcelRows($snapshot));
        $this->recordTransitions($snapshot->reference->id, $transitions);
    }

    public function forOrder(OrderId $order): ?Shipment
    {
        return $this->lockedWhere('s.order_id = ?', $order->toString());
    }

    public function withId(ShipmentId $shipment): ?Shipment
    {
        return $this->lockedWhere('s.id = ?', $shipment->toString());
    }

    public function withTrackingCode(TrackingCode $trackingCode): ?Shipment
    {
        return $this->lockedWhere('s.tracking_code = ?', $trackingCode->snowflake->toInt());
    }

    /** @param 's.order_id = ?'|'s.id = ?'|'s.tracking_code = ?' $condition */
    private function lockedWhere(string $condition, int|string $value): ?Shipment
    {
        $row = $this->connection->selectOne(<<<SQL
            SELECT s.*, (
                SELECT t.reason FROM shipment_transitions AS t
                 WHERE t.shipment_id = s.id AND t.to_status = 'delivery_failed'
                 ORDER BY t.occurred_at DESC, t.id DESC
                 LIMIT 1
            ) AS last_failure
              FROM shipments AS s
             WHERE {$condition}
               FOR UPDATE OF s
            SQL, [$value]);

        return $row === null ? null : Shipment::fromSnapshot($this->snapshotOf($row));
    }

    public function save(Shipment $shipment): void
    {
        $transitions = $shipment->releaseTransitions();
        if ($transitions === []) {
            return;
        }
        $snapshot = $shipment->toSnapshot();
        // Optimistic lock: the row must still be at the version this shipment was loaded with.
        $loadedAt = $snapshot->version - count($transitions);
        $updated = $this->connection->update(
            'UPDATE shipments SET status = ?, delivery_attempts = ?, label_object_key = ?, updated_at = ?, version = ? WHERE id = ? AND version = ?',
            [
                $snapshot->status->value,
                $snapshot->attempts->made,
                $snapshot->label?->objectKey,
                self::lastChange($transitions, $snapshot->createdAt),
                $snapshot->version,
                $snapshot->reference->id->toString(),
                $loadedAt,
            ],
        );
        if ($updated !== 1) {
            throw ShipmentChangedMeanwhile::withId($snapshot->reference->id->toString(), $loadedAt);
        }
        $this->recordTransitions($snapshot->reference->id, $transitions);
        $this->recordVisits($snapshot->reference->id, $shipment->releaseVisits());
    }

    private function snapshotOf(stdClass $row): ShipmentSnapshot
    {
        $id = ShipmentId::fromString((string) $row->id);

        return new ShipmentSnapshot(
            new ShipmentReference($id, new TrackingCode(Snowflake::fromInt((int) $row->tracking_code)), OrderId::fromString((string) $row->order_id)),
            CarrierCode::of((string) $row->carrier_code),
            FulfillmentCenterCode::of((string) $row->origin),
            Recipient::of((string) $row->recipient_name, (string) $row->recipient_email),
            Address::builder()
                ->thoroughfare((string) $row->dest_thoroughfare_type, (string) $row->dest_thoroughfare_name)
                ->number((string) $row->dest_number)
                ->complement($row->dest_complement === null ? null : (string) $row->dest_complement)
                ->divisions(Divisions::fromJson((string) $row->dest_divisions))
                ->postalCode((string) $row->dest_postal_code)
                ->coordinates(self::decimal($row->dest_latitude), self::decimal($row->dest_longitude))
                ->build(),
            $this->parcelsOf($id),
            ShipmentStatus::from((string) $row->status),
            DeliveryAttempts::restore(
                (int) $row->delivery_attempts,
                $row->last_failure === null ? null : DeliveryFailure::from((string) $row->last_failure),
            ),
            $row->label_object_key === null ? null : ShippingLabel::storedAt((string) $row->label_object_key),
            self::instant((string) $row->created_at),
            (int) $row->version,
        );
    }

    private function parcelsOf(ShipmentId $shipment): Parcels
    {
        $rows = $this->connection->table('parcels')->where('shipment_id', $shipment->toString())->orderBy('parcel_number')->get();

        return Parcels::of(...$rows->map(static fn(stdClass $row): Parcel => new Parcel(
            Weight::ofGrams((int) $row->weight_grams),
            Dimensions::ofMillimetres((int) $row->length_mm, (int) $row->width_mm, (int) $row->height_mm),
        ))->all());
    }

    /** @param list<StatusTransition> $transitions */
    private function recordTransitions(ShipmentId $shipment, array $transitions): void
    {
        $this->connection->table('shipment_transitions')->insert(array_map(static fn(StatusTransition $transition): array => [
            'shipment_id' => $shipment->toString(),
            'from_status' => $transition->from?->value,
            'to_status' => $transition->to->value,
            'reason' => $transition->reason,
            'location' => $transition->location,
            'occurred_at' => $transition->at->format(self::TIMESTAMP),
        ], $transitions));
    }

    /**
     * The proof of delivery lives here, next to the reason of every failed visit;
     * the proof_of_delivery CHECK refuses a delivered visit without a receiver.
     *
     * @param list<DeliveryAttempt> $visits
     */
    private function recordVisits(ShipmentId $shipment, array $visits): void
    {
        if ($visits === []) {
            return;
        }
        $this->connection->table('delivery_attempts')->insert(array_map(static fn(DeliveryAttempt $visit): array => [
            'id' => Uuid::uuid7()->toString(),
            'shipment_id' => $shipment->toString(),
            'attempt_number' => $visit->number,
            'outcome' => $visit->outcome->value,
            'reason' => $visit->failure?->value,
            'receiver_name' => $visit->proof?->receiverName,
            'receiver_document' => $visit->proof?->receiverDocument,
            'occurred_at' => $visit->at->format(self::TIMESTAMP),
        ], $visits));
    }

    /** @return list<array<string, int|string>> */
    private static function parcelRows(ShipmentSnapshot $snapshot): array
    {
        $rows = [];
        foreach ($snapshot->parcels as $index => $parcel) {
            $rows[] = [
                'shipment_id' => $snapshot->reference->id->toString(),
                'parcel_number' => $index + 1,
                'weight_grams' => $parcel->weight->grams(),
                'length_mm' => $parcel->dimensions->lengthMm,
                'width_mm' => $parcel->dimensions->widthMm,
                'height_mm' => $parcel->dimensions->heightMm,
            ];
        }

        return $rows;
    }

    /** @param list<StatusTransition> $transitions */
    private static function lastChange(array $transitions, DateTimeImmutable $createdAt): string
    {
        $last = $transitions === [] ? $createdAt : $transitions[array_key_last($transitions)]->at;

        return $last->format(self::TIMESTAMP);
    }

    private static function instant(string $timestamptz): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamptz)->setTimezone(new DateTimeZone('UTC'));
    }

    /** A numeric column comes back as text, or null. */
    private static function decimal(mixed $column): ?float
    {
        return $column === null ? null : (float) $column;
    }
}
