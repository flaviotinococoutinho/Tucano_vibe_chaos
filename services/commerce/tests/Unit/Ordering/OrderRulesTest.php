<?php

declare(strict_types=1);

namespace Tests\Unit\Ordering;

use Closure;
use Commerce\Ordering\Domain\Customer\EmailAddress;
use Commerce\Ordering\Domain\Customer\PersonName;
use Commerce\Ordering\Domain\Error\InvalidOrder;
use Commerce\Ordering\Domain\Error\ProductUnavailable;
use Commerce\Ordering\Domain\Order\FulfillmentCenterCode;
use Commerce\Ordering\Domain\Order\OrderLine;
use Commerce\Ordering\Domain\Order\OrderLines;
use Commerce\Ordering\Domain\Order\Quantity;
use Commerce\Ordering\Domain\Order\TrackingCode;
use Commerce\Ordering\Domain\Product\CatalogProduct;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\OrderBuilder;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

final class OrderRulesTest extends TestCase
{
    #[Test]
    public function the_total_is_the_sum_of_the_lines(): void
    {
        $lines = OrderLines::of(
            OrderBuilder::line('BOOK-DDD-001', 'Domain-Driven Design', 2, 18990),
            OrderBuilder::line('HOME-MUG-001', 'Caneca Tucano', 3, 4990),
        );

        self::assertSame(52950, $lines->total()->cents());
    }

    #[Test]
    public function a_sku_appears_in_one_line_only(): void
    {
        $this->expectException(InvalidOrder::class);

        OrderLines::of(
            OrderBuilder::line('BOOK-DDD-001', 'Domain-Driven Design', 1, 18990),
            OrderBuilder::line('BOOK-DDD-001', 'Domain-Driven Design', 1, 18990),
        );
    }

    #[Test]
    public function a_discontinued_product_cannot_be_ordered(): void
    {
        $product = new CatalogProduct(Sku::of('ELEC-MON-027'), 'Monitor 27 polegadas', Money::of(159990, Currency::brl()), ProductStatus::Discontinued);

        $this->expectException(ProductUnavailable::class);

        OrderLine::of($product, Quantity::of(1));
    }

    #[Test]
    public function values_are_normalized(): void
    {
        self::assertSame('ana@example.com', (string) EmailAddress::of('  Ana@Example.com '));
        self::assertSame('BOOK-DDD-001', (string) Sku::of('book-ddd-001'));
        self::assertSame('Ana Souza', (string) PersonName::of('  Ana   Souza '));
    }

    /** @return iterable<string, array{Closure(): mixed}> */
    public static function invalidValues(): iterable
    {
        yield 'no lines' => [static fn() => OrderLines::of()];
        yield 'zero units' => [static fn() => Quantity::of(0)];
        yield 'eleven units' => [static fn() => Quantity::of(11)];
        yield 'bad e-mail' => [static fn() => EmailAddress::of('ana@')];
        yield 'blank name' => [static fn() => PersonName::of('   ')];
        yield 'bad SKU' => [static fn() => Sku::of('a b')];
        yield 'bad warehouse' => [static fn() => FulfillmentCenterCode::of('gru1')];
        yield 'tracking code with symbols Crockford leaves out' => [static fn() => TrackingCode::of('TX02PWW6JFR5GIL')];
        yield 'tracking code in lowercase' => [static fn() => TrackingCode::of('tx02pww6jfr5g00')];
    }

    /** @param Closure(): mixed $build */
    #[Test]
    #[DataProvider('invalidValues')]
    public function invalid_values_are_refused(Closure $build): void
    {
        $this->expectException(InvalidOrder::class);

        $build();
    }
}
