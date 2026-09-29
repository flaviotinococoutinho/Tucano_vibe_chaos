<?php

declare(strict_types=1);

namespace Tests\Builders;

use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Customer\EmailAddress;
use Commerce\Ordering\Domain\Customer\PersonName;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\OrderNumber;
use Commerce\Ordering\Domain\Order\OrderSnapshot;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Ordering\Domain\Store\StoreSlug;
use DateTimeImmutable;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/** Test data builder: sensible defaults, change only what the test is about. */
final class OrderBuilder
{
    /** Each order built gets its own number, like the next sequence of the same millisecond. */
    private static int $sequence = 0;

    /** @var list<OrderLine> */
    private array $lines;

    private DateTimeImmutable $placedAt;

    private ?CustomerId $customer = null;

    private StoreSlug $store;

    private function __construct()
    {
        $this->lines = [self::line('BOOK-DDD-001', 'Domain-Driven Design', 1, 18990)];
        $this->placedAt = new DateTimeImmutable('2026-09-27T12:00:00Z');
        // The store of the books: the default line is one of them.
        $this->store = StoreSlug::of('arara');
    }

    public static function anOrder(): self
    {
        return new self();
    }

    public static function line(string $sku, string $name, int $quantity, int $unitPriceCents): OrderLine
    {
        return new OrderLine(Sku::of($sku), $name, Quantity::of($quantity), Money::of($unitPriceCents, Currency::brl()));
    }

    public function withLines(OrderLine ...$lines): self
    {
        $this->lines = array_values($lines);

        return $this;
    }

    public function placedAt(string $instant): self
    {
        $this->placedAt = new DateTimeImmutable($instant);

        return $this;
    }

    public function by(CustomerId $customer): self
    {
        $this->customer = $customer;

        return $this;
    }

    public function in(string $store): self
    {
        $this->store = StoreSlug::of($store);

        return $this;
    }

    /** Placed, and paid a minute later, with what happened so far already handed over. */
    public function paid(): Order
    {
        $order = $this->place();
        $order->markAsPaid($this->placedAt->modify('+1 minute'));
        $order->releaseEvents();
        $order->releaseTransitions();

        return $order;
    }

    /**
     * Placed, as a row from before the stores loads (ADR 0031): no store, and nothing left to
     * hand over, because what it did happened before.
     */
    public function placedBeforeTheStores(): Order
    {
        $placed = $this->place()->toSnapshot();

        return Order::fromSnapshot(new OrderSnapshot(
            $placed->id,
            $placed->number,
            null,
            $placed->customer,
            $placed->address,
            $placed->lines,
            $placed->fulfillmentCenter,
            $placed->status,
            $placed->placedAt,
            $placed->reservationExpiresAt,
            $placed->version,
            $placed->trackingCode,
            $placed->cancellationReason,
        ));
    }

    public function place(): Order
    {
        return Order::place(
            OrderId::generate(),
            OrderNumber::fromSnowflake(Snowflake::compose($this->placedAt->getTimestamp() * 1000, new NodeId(1, 1), self::$sequence++ % 4096)),
            $this->store,
            Customer::of($this->customer ?? CustomerId::generate(), PersonName::of('Ana Souza'), EmailAddress::of('ana@example.com')),
            Addresses::paulista(),
            OrderLines::of(...$this->lines),
            FulfillmentCenterCode::of('GRU1'),
            $this->placedAt,
            $this->placedAt->modify('+15 minutes'),
        );
    }
}
