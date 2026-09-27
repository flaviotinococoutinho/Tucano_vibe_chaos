<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Address;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;

/** CEP with digits only (CHAR(8)); "01310-100" and "01310100" are the same code. */
final readonly class PostalCode implements Stringable
{
    private function __construct(private string $digits) {}

    public static function of(string $value): self
    {
        $digits = str_replace(['-', '.', ' '], '', $value);
        if (preg_match('/^\d{8}$/', $digits) !== 1) {
            throw InvalidOrder::because(sprintf('"%s" is not a valid CEP.', $value));
        }

        return new self($digits);
    }

    public function __toString(): string
    {
        return $this->digits;
    }
}
