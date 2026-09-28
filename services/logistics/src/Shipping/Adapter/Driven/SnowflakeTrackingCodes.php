<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Logistics\Shipping\Application\Port\Driven\ForIssuingTrackingCodes;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Tucano\SharedKernel\Identity\Snowflake\SnowflakeGenerator;

/** Worker 11 under PHP-FPM and 12 in the order intake, so the code tells which process created it. */
final readonly class SnowflakeTrackingCodes implements ForIssuingTrackingCodes
{
    public function __construct(private SnowflakeGenerator $generator) {}

    public function next(): TrackingCode
    {
        return TrackingCode::fromSnowflake($this->generator->next());
    }
}
