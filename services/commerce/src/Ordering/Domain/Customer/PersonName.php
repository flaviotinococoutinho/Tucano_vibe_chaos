<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Customer;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;

final readonly class PersonName implements Stringable
{
    private const int MAX_LENGTH = 120;

    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidOrder::because('A name needs between 1 and 120 characters.');
        }

        return new self($trimmed);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
