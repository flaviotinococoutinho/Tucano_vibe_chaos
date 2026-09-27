<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

use Logistics\Shipping\Domain\Error\InvalidShipment;
use Stringable;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/**
 * Public code of a shipment: a Snowflake stored as BIGINT and shown as TX plus
 * 13 Crockford Base32 symbols (CHAR(15)), such as TX02PQRFBTW5G03. The code
 * itself tells when and in which process the shipment was created.
 */
final readonly class TrackingCode implements Stringable
{
    private const string PREFIX = 'TX';

    public function __construct(public Snowflake $snowflake) {}

    /** Reads the code as people and carriers write it, TX plus 13 symbols. */
    public static function fromString(string $code): self
    {
        if (preg_match('/^TX[0-9A-HJKMNP-TV-Z]{13}$/', $code) !== 1) {
            throw InvalidShipment::because(sprintf('"%s" is not a tracking code.', $code));
        }

        return new self(Snowflake::fromBase32(substr($code, 2)));
    }

    public function __toString(): string
    {
        return self::PREFIX . $this->snowflake->toBase32();
    }
}
