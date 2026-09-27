<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Commerce\Ordering\Application\PlaceOrderCommand;
use Commerce\Ordering\Application\RequestedItem;
use Commerce\Ordering\Application\UseCase\PlaceOrder;
use Commerce\Ordering\Domain\Address\BrazilianState;
use Commerce\Ordering\Domain\Address\PostalCode;
use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Customer\Customer;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Customer\EmailAddress;
use Commerce\Ordering\Domain\Customer\PersonName;
use Commerce\Ordering\Domain\Error\ProductUnavailable;
use Commerce\Ordering\Domain\Error\StockNotReserved;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Order\ReservationWindow;
use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Commerce\Shared\Application\Idempotency\IdempotencyKey;
use Commerce\Shared\Application\Idempotency\IdempotencyKeyReused;
use Commerce\Shared\Application\Idempotency\Outcome;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Ordering\FakeStockReservations;
use Tests\Doubles\Ordering\InMemoryCatalog;
use Tests\Doubles\Ordering\InMemoryOrders;
use Tests\Doubles\Ordering\SequentialOrderNumbers;
use Tests\Doubles\Shared\DirectTransactions;
use Tests\Doubles\Shared\InMemoryRequestMemory;
use Tests\Doubles\Shared\RecordedEvents;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;
use Tucano\SharedKernel\Time\FrozenClock;

final class PlaceOrderTest extends TestCase
{
    private const string CUSTOMER = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private InMemoryOrders $orders;

    private FakeStockReservations $stock;

    private RecordedEvents $events;

    private PlaceOrder $placeOrder;

    protected function setUp(): void
    {
        $this->orders = new InMemoryOrders();
        $this->stock = new FakeStockReservations('BHZ1');
        $this->events = new RecordedEvents();
        $this->placeOrder = new PlaceOrder(
            new DirectTransactions(),
            new InMemoryRequestMemory(),
            new InMemoryCatalog(
                self::product('BOOK-DDD-001', 'Domain-Driven Design', 18990, ProductStatus::Active),
                self::product('HOME-MUG-001', 'Caneca de cerâmica', 4990, ProductStatus::Active),
                self::product('ELEC-MP3-001', 'Tocador de MP3', 19990, ProductStatus::Discontinued),
            ),
            $this->stock,
            new SequentialOrderNumbers(),
            $this->orders,
            $this->events,
            ReservationWindow::ofMinutes(15),
            new FrozenClock('2026-09-27T12:00:00Z'),
        );
    }

    #[Test]
    public function a_new_order_waits_for_payment_with_its_stock_held(): void
    {
        $placed = $this->placeOrder->placeOrder(self::command('key-1', ['BOOK-DDD-001' => 2, 'HOME-MUG-001' => 1]));
        $order = $placed->order->toArray();

        self::assertSame(Outcome::Fresh, $placed->outcome);
        self::assertSame('pending_payment', $order['status']);
        self::assertSame('BHZ1', $order['fulfillmentCenter']);
        self::assertSame(['amount' => 42970, 'currency' => 'BRL'], $order['total']);
        self::assertSame('2026-09-27T12:15:00.000+00:00', $order['reservationExpiresAt']);
        self::assertSame([$placed->order->orderId()], $this->stock->reservedFor);
        self::assertSame(1, $this->orders->count());
        self::assertSame(['tucano.commerce.order.placed'], $this->events->types());
    }

    #[Test]
    public function the_same_request_twice_places_one_order(): void
    {
        $first = $this->placeOrder->placeOrder(self::command('key-1', ['BOOK-DDD-001' => 1]));
        $again = $this->placeOrder->placeOrder(self::command('key-1', ['BOOK-DDD-001' => 1]));

        self::assertSame(Outcome::Replayed, $again->outcome);
        self::assertSame($first->order->toArray(), $again->order->toArray());
        self::assertSame(1, $this->orders->count());
        self::assertCount(1, $this->events->events);
    }

    #[Test]
    public function a_key_used_for_another_order_is_refused(): void
    {
        $this->placeOrder->placeOrder(self::command('key-1', ['BOOK-DDD-001' => 1]));

        $this->expectException(IdempotencyKeyReused::class);

        $this->placeOrder->placeOrder(self::command('key-1', ['BOOK-DDD-001' => 2]));
    }

    #[Test]
    public function a_product_outside_the_catalog_is_refused_before_any_stock_is_held(): void
    {
        $this->expectExceptionObject(ProductUnavailable::unknown(Sku::of('BOOK-NONE-001')));

        try {
            $this->placeOrder->placeOrder(self::command('key-1', ['BOOK-DDD-001' => 1, 'BOOK-NONE-001' => 1]));
        } finally {
            self::assertSame([], $this->stock->reservedFor);
            self::assertSame(0, $this->orders->count());
        }
    }

    #[Test]
    public function a_discontinued_product_is_refused(): void
    {
        $this->expectExceptionObject(ProductUnavailable::discontinued(Sku::of('ELEC-MP3-001')));

        $this->placeOrder->placeOrder(self::command('key-1', ['ELEC-MP3-001' => 1]));
    }

    #[Test]
    public function without_stock_there_is_no_order(): void
    {
        $this->stock->runOutOf('BOOK-DDD-001');

        try {
            $this->placeOrder->placeOrder(self::command('key-1', ['BOOK-DDD-001' => 1]));
            self::fail('The order should have been refused.');
        } catch (StockNotReserved) {
            self::assertSame(0, $this->orders->count());
            self::assertSame([], $this->events->events);
        }
    }

    private static function product(string $sku, string $name, int $cents, ProductStatus $status): CatalogProduct
    {
        return new CatalogProduct(Sku::of($sku), $name, Money::of($cents, Currency::brl()), $status);
    }

    /** @param non-empty-array<string, int> $items SKU => quantity */
    private static function command(string $key, array $items): PlaceOrderCommand
    {
        $requested = [];
        foreach ($items as $sku => $quantity) {
            $requested[] = new RequestedItem(Sku::of($sku), Quantity::of($quantity));
        }

        return new PlaceOrderCommand(
            IdempotencyKey::of($key),
            new Customer(CustomerId::fromString(self::CUSTOMER), PersonName::of('Ana Souza'), EmailAddress::of('ana@example.com')),
            new ShippingAddress('Rua da Bahia', '1200', null, 'Centro', 'Belo Horizonte', BrazilianState::MG, PostalCode::of('30160-011')),
            $requested,
        );
    }
}
