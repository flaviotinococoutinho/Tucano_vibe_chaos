<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Stringable;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/** Public, time-ordered order number (a Snowflake shown in decimal, like a tweet id). */
final readonly class OrderNumber implements Stringable
{
    public function __construct(public Snowflake $snowflake) {}

    public static function fromString(string $decimal): self
    {
        return new self(Snowflake::fromString($decimal));
    }

    public function __toString(): string
    {
        return $this->snowflake->toString();
    }
}
