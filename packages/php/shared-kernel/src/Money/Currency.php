<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Money;

use InvalidArgumentException;
use Stringable;

/** ISO 4217 code: always three uppercase letters, stored as CHAR(3). */
final readonly class Currency implements Stringable
{
    private function __construct(private string $code) {}

    public static function fromCode(string $code): self
    {
        if (preg_match('/^[A-Z]{3}$/', $code) !== 1) {
            throw new InvalidArgumentException(sprintf('"%s" is not an ISO 4217 currency code.', $code));
        }

        return new self($code);
    }

    public static function brl(): self
    {
        return new self('BRL');
    }

    public function equals(self $other): bool
    {
        return $other->code === $this->code;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
