<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Customer;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;
use Tucano\SharedKernel\Privacy\DataCategory;
use Tucano\SharedKernel\Privacy\Sensitive;

/**
 * Stored as VARCHAR(254), the practical limit from RFC 5321. Personal data under the
 * LGPD: printed by accident it shows a***@domain; reveal() gives the address.
 */
final readonly class EmailAddress implements Stringable
{
    private const int MAX_LENGTH = 254;

    private function __construct(private Sensitive $value) {}

    public static function of(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (strlen($normalized) > self::MAX_LENGTH || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            // The address stays out of the message: errors end up in logs.
            throw InvalidOrder::because('The e-mail address is not valid.');
        }

        return new self(Sensitive::of($normalized, DataCategory::Email));
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
