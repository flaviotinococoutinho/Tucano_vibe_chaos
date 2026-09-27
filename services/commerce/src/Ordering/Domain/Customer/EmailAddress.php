<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Customer;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;

/** Stored as VARCHAR(254), the practical limit from RFC 5321. */
final readonly class EmailAddress implements Stringable
{
    private const int MAX_LENGTH = 254;

    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        $normalized = strtolower(trim($value));
        if (strlen($normalized) > self::MAX_LENGTH || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidOrder::because(sprintf('"%s" is not a valid e-mail address.', $value));
        }

        return new self($normalized);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
