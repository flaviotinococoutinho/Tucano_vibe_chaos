<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Closure;
use Commerce\Inventory\Application\Port\Driving\ForCommittingStock;
use Commerce\Inventory\Application\Port\Driving\ForReleasingStock;
use Commerce\Inventory\Application\Port\Driving\ForReservingStock;
use Commerce\Inventory\Application\ReservedStock;
use Commerce\Inventory\Application\StockRequest;
use Commerce\Inventory\Domain\InsufficientStock;
use Commerce\Inventory\Domain\StockContention;
use Commerce\Ordering\Adapter\Driven\InventoryStockReservations;
use Commerce\Ordering\Domain\Address\BrazilianState;
use Commerce\Ordering\Domain\Address\PostalCode;
use Commerce\Ordering\Domain\Address\ShippingAddress;
use Commerce\Ordering\Domain\Error\StockNotReserved;
use Commerce\Ordering\Domain\Order\OrderId;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Shared\Application\Isolation;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;

final class InventoryStockReservationsTest extends TestCase
{
    #[Test]
    public function the_order_goes_in_plain_values_and_the_center_comes_back(): void
    {
        $asked = [];
        $adapter = self::adapter(static function (StockRequest $request) use (&$asked): ReservedStock {
            $asked[] = [$request->orderId, $request->destinationState, count($request->items)];

            return new ReservedStock('BHZ1');
        });
        $order = OrderId::generate();

        $center = $adapter->reserve($order, self::lines(), self::address(), new DateTimeImmutable('2026-09-27T12:15:00Z'));

        self::assertSame('BHZ1', (string) $center);
        self::assertSame([[$order->toString(), 'SP', 1]], $asked);
    }

    #[Test]
    public function a_refusal_of_inventory_becomes_an_ordering_error_of_the_same_category(): void
    {
        foreach ([InsufficientStock::in(['GRU1' => ['BOOK-DDD-001']]), StockContention::on('BOOK-DDD-001', 'GRU1', 3)] as $refusal) {
            $adapter = self::adapter(static fn(): ReservedStock => throw $refusal);
            try {
                $adapter->reserve(OrderId::generate(), self::lines(), self::address(), new DateTimeImmutable('2026-09-27T12:15:00Z'));
                self::fail('The refusal was expected.');
            } catch (StockNotReserved $translated) {
                self::assertSame([$refusal->category(), $refusal->getMessage(), $refusal], [$translated->category(), $translated->getMessage(), $translated->getPrevious()]);
            }
        }
    }

    /** @param Closure(StockRequest): ReservedStock $answer */
    private static function adapter(Closure $answer): InventoryStockReservations
    {
        $inventory = new readonly class ($answer) implements ForReservingStock {
            /** @param Closure(StockRequest): ReservedStock $answer */
            public function __construct(private Closure $answer) {}

            public function requiredIsolation(): Isolation
            {
                return Isolation::ReadCommitted;
            }

            public function reserve(StockRequest $request): ReservedStock
            {
                return ($this->answer)($request);
            }
        };
        $releases = new class implements ForReleasingStock {
            public function release(string $orderId): void {}
        };
        $sales = new class implements ForCommittingStock {
            public function commit(string $orderId): void {}
        };

        return new InventoryStockReservations($inventory, $releases, $sales);
    }

    private static function lines(): OrderLines
    {
        return OrderLines::of(OrderBuilder::line('BOOK-DDD-001', 'Domain-Driven Design', 1, 18990));
    }

    private static function address(): ShippingAddress
    {
        return new ShippingAddress('Avenida Paulista', '1000', null, 'Bela Vista', 'São Paulo', BrazilianState::SP, PostalCode::of('01310-100'));
    }
}
