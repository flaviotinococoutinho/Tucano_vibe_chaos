<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Money;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\CurrencyMismatch;
use Tucano\SharedKernel\Money\Money;

#[CoversClass(Money::class)]
#[CoversClass(Currency::class)]
#[CoversClass(CurrencyMismatch::class)]
final class MoneyTest extends TestCase
{
    #[Test]
    public function it_adds_and_multiplies_in_cents(): void
    {
        $price = Money::of(12990, Currency::brl());

        $total = $price->multiply(3)->add(Money::of(1500, Currency::brl()));

        self::assertSame(40470, $total->cents());
        self::assertTrue($total->equals(Money::of(40470, Currency::brl())));
    }

    #[Test]
    public function it_never_mixes_currencies(): void
    {
        $this->expectException(CurrencyMismatch::class);

        Money::of(100, Currency::brl())->add(Money::of(100, Currency::fromCode('USD')));
    }

    #[Test]
    public function it_refuses_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::of(-1, Currency::brl());
    }

    #[Test]
    public function currency_codes_are_three_uppercase_letters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Currency::fromCode('brl');
    }

    #[Test]
    public function it_compares_amounts_of_the_same_currency(): void
    {
        self::assertTrue(Money::of(200, Currency::brl())->isGreaterThan(Money::of(199, Currency::brl())));
        self::assertFalse(Money::zero(Currency::brl())->isGreaterThan(Money::of(1, Currency::brl())));
    }

    #[Test]
    public function it_is_serialized_as_amount_and_currency(): void
    {
        self::assertSame('{"amount":12990,"currency":"BRL"}', json_encode(Money::of(12990, Currency::brl())));
    }
}
