<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driven;

use Commerce\Ordering\Application\CustomerOrders;
use Commerce\Ordering\Application\OrderSummary;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Application\Port\Driven\ForReadingOrderViews;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrderViews;
use Commerce\Ordering\Application\ProjectionOutcome;
use Commerce\Ordering\Application\StatusMove;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderListUnavailable;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Order\OrderStatus;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Ordering\Domain\Store\StoreSlug;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use MongoDB\Driver\Exception\ConnectionException;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;
use Tucano\SharedKernel\Identity\UuidIdentifier;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/**
 * commerce_read.order_views: one document per order, the customer's list in a store. The
 * projection opens a view with order.placed and moves it with every later event, and a move
 * applies only to a view at an older version: a redelivery, a replay or an event late matches
 * nothing. A page reads the views of one store and one customer (ADR 0031), by the index
 * store_customer_history; a view from before the stores has no store and no page has it. The
 * collection validator wants 64-bit integers where PHP writes a small number as a 32-bit one,
 * hence the Int64.
 */
final readonly class MongoOrderViews implements ForStoringOrderViews, ForReadingOrderViews
{
    private const int DUPLICATE_KEY = 11000;

    /** A list out of reach is worth another look in about the time a restart of MongoDB takes. */
    private const int RETRY_AFTER_SECONDS = 5;

    private const array AS_ARRAYS = ['root' => 'array', 'document' => 'array', 'array' => 'array'];

    private Collection $views;

    public function __construct(Database $readModels)
    {
        $this->views = $readModels->selectCollection('order_views');
    }

    public function open(OrderSummary $order): bool
    {
        try {
            $this->views->insertOne([
                '_id' => self::uuid($order->orderId),
                'orderNumber' => new Int64($order->orderNumber->snowflake->toInt()),
                // Null for an order.placed from before the stores: the view then belongs to no list.
                'store' => $order->store === null ? null : (string) $order->store,
                'customerId' => self::uuid($order->customerId),
                'status' => $order->status->value,
                'cancellationReason' => $order->cancellationReason?->value,
                'total' => ['amount' => new Int64($order->total()->cents()), 'currency' => $order->total()->currency()->code()],
                'lines' => array_map(static fn(OrderLine $line): array => [
                    'sku' => (string) $line->sku,
                    'name' => $line->productName,
                    'quantity' => $line->quantity->value,
                    'unitPrice' => new Int64($line->unitPrice->cents()),
                ], iterator_to_array($order->lines, false)),
                // Filled from logistics.shipments.v2 in a later step; the list does not show it yet.
                'shipment' => null,
                'placedAt' => new UTCDateTime($order->placedAt),
                'updatedAt' => new UTCDateTime($order->updatedAt),
                'version' => new Int64($order->status->orderVersion()),
            ]);
        } catch (BulkWriteException $error) {
            if (self::isDuplicate($error)) {
                return false;
            }

            throw $error;
        }

        return true;
    }

    public function move(StatusMove $move): ProjectionOutcome
    {
        $id = self::uuid($move->orderId);
        $moved = $this->views->updateOne(
            ['_id' => $id, 'version' => ['$lt' => new Int64($move->version)]],
            ['$set' => [
                'status' => $move->status->value,
                'cancellationReason' => $move->cancellationReason?->value,
                'updatedAt' => new UTCDateTime($move->at),
                'version' => new Int64($move->version),
            ]],
        );
        if ($moved->getMatchedCount() > 0) {
            return ProjectionOutcome::Applied;
        }

        // A move never creates a view: the validator wants the lines and the customer, which only order.placed carries.
        return $this->views->countDocuments(['_id' => $id], ['limit' => 1]) > 0 ? ProjectionOutcome::Duplicate : ProjectionOutcome::Missing;
    }

    public function page(StoreSlug $store, CustomerId $customer, Page $page): CustomerOrders
    {
        $theirs = ['store' => (string) $store, 'customerId' => self::uuid($customer)];
        try {
            $total = $this->views->countDocuments($theirs);
            $views = $page->offset() >= $total ? [] : $this->views->find($theirs, [
                // Newest first; the id, a UUIDv7, settles two orders placed in the same millisecond.
                'sort' => ['placedAt' => -1, '_id' => -1],
                'skip' => $page->offset(),
                'limit' => $page->size,
                'typeMap' => self::AS_ARRAYS,
            ])->toArray();
        } catch (ConnectionException $outage) {
            throw OrderListUnavailable::forSeconds(self::RETRY_AFTER_SECONDS, $outage);
        }

        return CustomerOrders::of($page, $total, array_values(array_map(self::summaryOf(...), $views)));
    }

    /** @param array<mixed> $view */
    private static function summaryOf(array $view): OrderSummary
    {
        /** @var array{_id: Binary, orderNumber: Int64|int, store?: ?string, customerId: Binary, status: string, cancellationReason?: ?string, total: array{currency: string}, lines: list<array{sku: string, name: string, quantity: int, unitPrice: Int64|int}>, placedAt: UTCDateTime, updatedAt: UTCDateTime} $view */
        $currency = Currency::fromCode($view['total']['currency']);

        return OrderSummary::of(
            OrderId::fromBytes($view['_id']->getData()),
            OrderNumber::fromSnowflake(Snowflake::fromInt(self::integer($view['orderNumber']))),
            isset($view['store']) ? StoreSlug::of($view['store']) : null,
            CustomerId::fromBytes($view['customerId']->getData()),
            OrderStatus::from($view['status']),
            CancellationReason::tryFrom($view['cancellationReason'] ?? ''),
            OrderLines::of(...array_map(static fn(array $line): OrderLine => new OrderLine(
                Sku::of($line['sku']),
                $line['name'],
                Quantity::of($line['quantity']),
                Money::of(self::integer($line['unitPrice']), $currency),
            ), $view['lines'])),
            $view['placedAt']->toDateTimeImmutable(),
            $view['updatedAt']->toDateTimeImmutable(),
        );
    }

    private static function isDuplicate(BulkWriteException $error): bool
    {
        foreach ($error->getWriteResult()->getWriteErrors() as $writeError) {
            if ($writeError->getCode() === self::DUPLICATE_KEY) {
                return true;
            }
        }

        return false;
    }

    /** The driver hands a 64-bit integer back as Int64. */
    private static function integer(Int64|int $value): int
    {
        return is_int($value) ? $value : (int) (string) $value;
    }

    private static function uuid(UuidIdentifier $id): Binary
    {
        return new Binary($id->toBytes(), Binary::TYPE_UUID);
    }
}
