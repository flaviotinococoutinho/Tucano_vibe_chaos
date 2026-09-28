<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Customer;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;
use Tucano\SharedKernel\Privacy\DataCategory;
use Tucano\SharedKernel\Privacy\Sensitive;

/**
 * The name of a customer, personal data under the LGPD. Printed by accident (a log, a
 * dump, an error) it shows only initials; reveal() is for the places that need the name.
 */
final readonly class PersonName implements Stringable
{
    private const int MAX_LENGTH = 120;

    private function __construct(private Sensitive $value) {}

    public static function of(string $value): self
    {
        $trimmed = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($trimmed === '' || mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw InvalidOrder::because('A name needs between 1 and 120 characters.');
        }

        return new self(Sensitive::of($trimmed, DataCategory::PersonName));
    }

    public function reveal(): string
    {
        return $this->value->reveal();
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
