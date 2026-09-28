<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Money;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Amount in the smallest unit (cents) plus the currency. Never a float:
 * 0.1 + 0.2 is not 0.3 in binary floating point.
 */
final readonly class Money implements JsonSerializable
{
    private function __construct(private int $cents, private Currency $currency) {}

    public static function of(int $cents, Currency $currency): self
    {
        if ($cents < 0) {
            throw new InvalidArgumentException(sprintf('Money cannot be negative, got %d.', $cents));
        }

        return new self($cents, $currency);
    }

    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->cents + $other->cents, $this->currency);
    }

    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new InvalidArgumentException(sprintf('Cannot multiply money by %d.', $factor));
        }

        return new self($this->cents * $factor, $this->currency);
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->cents > $other->cents;
    }

    public function equals(self $other): bool
    {
        return $other->currency->equals($this->currency) && $other->cents === $this->cents;
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /** @return array{amount: int, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->cents, 'currency' => $this->currency->code()];
    }

    /** @return array{amount: int, currency: string} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function assertSameCurrency(self $other): void
    {
        if (!$other->currency->equals($this->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }
}
