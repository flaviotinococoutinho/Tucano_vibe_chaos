<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Customer\EmailAddress;
use Commerce\Ordering\Domain\Customer\PersonName;
use Commerce\Ordering\Domain\Error\OrderChangedMeanwhile;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Order\OrderSnapshot;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Order\StatusTransition;
use Commerce\Ordering\Domain\Order\TrackingCode;
use Commerce\Ordering\Domain\Product\Sku;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use stdClass;
use Tucano\SharedKernel\Address\Address;
use Tucano\SharedKernel\Address\Divisions;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/** Maps the Order aggregate to orders, order_lines and the append-only order_status_transitions. */
final readonly class PostgresOrders implements ForStoringOrders
{
    public function __construct(private ConnectionInterface $connection) {}

    public function add(Order $order): void
    {
        $snapshot = $order->toSnapshot();
        $address = $snapshot->address;
        $total = $snapshot->lines->total();
        $placedAt = $snapshot->placedAt->format(DATE_RFC3339_EXTENDED);

        $this->connection->table('orders')->insert([
            'id' => $snapshot->id->toString(),
            'order_number' => $snapshot->number->snowflake->toInt(),
            'customer_id' => $snapshot->customer->id->toString(),
            'customer_name' => $snapshot->customer->name->reveal(),
            'customer_email' => $snapshot->customer->email->reveal(),
            'status' => $snapshot->status->value,
            'tracking_code' => self::code($snapshot->trackingCode),
            'cancellation_reason' => $snapshot->cancellationReason?->value,
            'fulfillment_center' => (string) $snapshot->fulfillmentCenter,
            'ship_thoroughfare_type' => $address->thoroughfare->type,
            'ship_thoroughfare_name' => $address->thoroughfare->name,
            'ship_number' => $address->number,
            'ship_complement' => $address->complement,
            'ship_divisions' => $address->divisions->toJson(),
            'ship_postal_code' => (string) $address->postalCode,
            'ship_latitude' => $address->coordinates?->latitude,
            'ship_longitude' => $address->coordinates?->longitude,
            'total_cents' => $total->cents(),
            'currency' => $total->currency()->code(),
            'reservation_expires_at' => $snapshot->reservationExpiresAt->format(DATE_RFC3339_EXTENDED),
            'placed_at' => $placedAt,
            'updated_at' => $placedAt,
            'version' => $snapshot->version,
        ]);

        $lines = [];
        foreach ($snapshot->lines as $index => $line) {
            $lines[] = [
                'order_id' => $snapshot->id->toString(),
                'line_number' => $index + 1,
                'sku' => (string) $line->sku,
                'product_name' => $line->productName,
                'quantity' => $line->quantity->value,
                'unit_price_cents' => $line->unitPrice->cents(),
            ];
        }
        $this->connection->table('order_lines')->insert($lines);
        $this->recordTransitions($snapshot->id, $order->releaseTransitions());
    }

    public function save(Order $order): void
    {
        $transitions = $order->releaseTransitions();
        if ($transitions === []) {
            return;
        }
        $snapshot = $order->toSnapshot();
        // Optimistic lock: the row must still be at the version this order was loaded with.
        $loadedAt = $snapshot->version - count($transitions);
        $updated = $this->connection->update(
            'UPDATE orders SET status = ?, tracking_code = ?, cancellation_reason = ?, version = ?, updated_at = ? WHERE id = ? AND version = ?',
            [$snapshot->status->value, self::code($snapshot->trackingCode), $snapshot->cancellationReason?->value, $snapshot->version, $transitions[array_key_last($transitions)]->at->format(DATE_RFC3339_EXTENDED), $snapshot->id->toString(), $loadedAt],
        );
        if ($updated !== 1) {
            throw OrderChangedMeanwhile::withId($snapshot->id->toString(), $loadedAt);
        }
        $this->recordTransitions($snapshot->id, $transitions);
    }

    public function nextExpired(DateTimeImmutable $now): ?Order
    {
        $row = $this->connection->selectOne(<<<'SQL'
            SELECT id FROM orders
             WHERE status = 'pending_payment' AND reservation_expires_at <= ?
             ORDER BY reservation_expires_at
             LIMIT 1
             FOR UPDATE SKIP LOCKED
            SQL, [$now->format(DATE_RFC3339_EXTENDED)]);

        return $row === null ? null : $this->get(OrderId::fromString((string) $row->id));
    }

    public function lock(OrderId $id): Order
    {
        $this->connection->select('SELECT 1 FROM orders WHERE id = ? FOR UPDATE', [$id->toString()]);

        return $this->get($id);
    }

    public function get(OrderId $id): Order
    {
        $row = $this->connection->table('orders')->where('id', $id->toString())->first();
        if ($row === null) {
            throw OrderNotFound::withId($id->toString());
        }
        $currency = Currency::fromCode((string) $row->currency);
        $lines = $this->connection->table('order_lines')->where('order_id', $id->toString())->orderBy('line_number')->get()
            ->map(static fn(stdClass $line): OrderLine => new OrderLine(
                Sku::of((string) $line->sku),
                (string) $line->product_name,
                Quantity::of((int) $line->quantity),
                Money::of((int) $line->unit_price_cents, $currency),
            ))
            ->all();

        return Order::fromSnapshot(new OrderSnapshot(
            $id,
            OrderNumber::fromSnowflake(Snowflake::fromInt((int) $row->order_number)),
            Customer::of(
                CustomerId::fromString((string) $row->customer_id),
                PersonName::of((string) $row->customer_name),
                EmailAddress::of((string) $row->customer_email),
            ),
            Address::builder()
                ->thoroughfare((string) $row->ship_thoroughfare_type, (string) $row->ship_thoroughfare_name)
                ->number((string) $row->ship_number)
                ->complement($row->ship_complement === null ? null : (string) $row->ship_complement)
                ->divisions(Divisions::fromJson((string) $row->ship_divisions))
                ->postalCode((string) $row->ship_postal_code)
                ->coordinates(self::decimal($row->ship_latitude), self::decimal($row->ship_longitude))
                ->build(),
            OrderLines::of(...$lines),
            FulfillmentCenterCode::of((string) $row->fulfillment_center),
            OrderStatus::from((string) $row->status),
            self::instant((string) $row->placed_at),
            self::instant((string) $row->reservation_expires_at),
            (int) $row->version,
            $row->tracking_code === null ? null : TrackingCode::of((string) $row->tracking_code),
            $row->cancellation_reason === null ? null : CancellationReason::from((string) $row->cancellation_reason),
        ));
    }

    /** @param list<StatusTransition> $transitions */
    private function recordTransitions(OrderId $id, array $transitions): void
    {
        $this->connection->table('order_status_transitions')->insert(array_map(static fn(StatusTransition $transition): array => [
            'order_id' => $id->toString(),
            'from_status' => $transition->from?->value,
            'to_status' => $transition->to->value,
            'reason' => $transition->reason,
            'occurred_at' => $transition->at->format(DATE_RFC3339_EXTENDED),
        ], $transitions));
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

    private static function code(?TrackingCode $trackingCode): ?string
    {
        return $trackingCode === null ? null : (string) $trackingCode;
    }
}
