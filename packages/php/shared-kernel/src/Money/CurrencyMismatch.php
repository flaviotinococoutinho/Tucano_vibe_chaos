<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Money;

use DomainException;

final class CurrencyMismatch extends DomainException
{
    public static function between(Currency $expected, Currency $actual): self
    {
        return new self(sprintf('Cannot combine %s with %s.', $expected, $actual));
    }
}
