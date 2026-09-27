<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

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

    public function __toString(): string
    {
        return self::PREFIX . $this->snowflake->toBase32();
    }
}
