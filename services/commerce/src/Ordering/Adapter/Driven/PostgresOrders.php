<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Address\BrazilianState;
use Commerce\Ordering\Domain\Address\Coordinates;
use Commerce\Ordering\Domain\Address\PostalCode;
use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Customer\EmailAddress;
use Commerce\Ordering\Domain\Customer\PersonName;
use Commerce\Ordering\Domain\Error\OrderNotFound;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Order\OrderSnapshot;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Product\Sku;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use stdClass;
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
            'customer_name' => (string) $snapshot->customer->name,
            'customer_email' => (string) $snapshot->customer->email,
            'status' => $snapshot->status->value,
            'fulfillment_center' => (string) $snapshot->fulfillmentCenter,
            'ship_street' => $address->street,
            'ship_number' => $address->number,
            'ship_complement' => $address->complement,
            'ship_district' => $address->district,
            'ship_city' => $address->city,
            'ship_state' => $address->state->value,
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

        $this->connection->table('order_status_transitions')->insert([
            'order_id' => $snapshot->id->toString(),
            'from_status' => null,
            'to_status' => $snapshot->status->value,
            'occurred_at' => $placedAt,
        ]);
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
            new OrderNumber(Snowflake::fromInt((int) $row->order_number)),
            new Customer(
                CustomerId::fromString((string) $row->customer_id),
                PersonName::of((string) $row->customer_name),
                EmailAddress::of((string) $row->customer_email),
            ),
            new ShippingAddress(
                (string) $row->ship_street,
                (string) $row->ship_number,
                $row->ship_complement === null ? null : (string) $row->ship_complement,
                (string) $row->ship_district,
                (string) $row->ship_city,
                BrazilianState::from((string) $row->ship_state),
                PostalCode::of((string) $row->ship_postal_code),
                $row->ship_latitude === null ? null : new Coordinates((float) $row->ship_latitude, (float) $row->ship_longitude),
            ),
            OrderLines::of(...$lines),
            FulfillmentCenterCode::of((string) $row->fulfillment_center),
            OrderStatus::from((string) $row->status),
            self::instant((string) $row->placed_at),
            self::instant((string) $row->reservation_expires_at),
            (int) $row->version,
        ));
    }

    private static function instant(string $timestamptz): DateTimeImmutable
    {
        return new DateTimeImmutable($timestamptz)->setTimezone(new DateTimeZone('UTC'));
    }
}
