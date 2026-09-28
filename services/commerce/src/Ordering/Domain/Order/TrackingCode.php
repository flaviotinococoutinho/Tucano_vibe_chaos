<?php

declare(strict_types=1);

namespace Commerce\Ordering\Domain\Order;

use Commerce\Ordering\Domain\Error\InvalidOrder;
use Stringable;

/**
 * Public code of the shipment that carries the order (CHAR(15)), as Logistics
 * publishes it: TX plus 13 Crockford Base32 symbols, such as TX02PQRFBTW5G03.
 * Ordering keeps it only to point the customer to the tracking page; what the
 * code tells inside (a Snowflake) belongs to Logistics.
 */
final readonly class TrackingCode implements Stringable
{
    private function __construct(private string $value) {}

    public static function of(string $value): self
    {
        if (preg_match('/^TX[0-9A-HJKMNP-TV-Z]{13}$/', $value) !== 1) {
            throw InvalidOrder::because(sprintf('"%s" is not a tracking code.', $value));
        }

        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
