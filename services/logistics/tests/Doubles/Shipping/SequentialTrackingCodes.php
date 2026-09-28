<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\Port\Driven\ForIssuingTrackingCodes;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

final class SequentialTrackingCodes implements ForIssuingTrackingCodes
{
    private const int NOON_2026_09_27 = 1_790_510_400_000;

    private int $sequence = 0;

    public function next(): TrackingCode
    {
        return TrackingCode::fromSnowflake(Snowflake::compose(self::NOON_2026_09_27, new NodeId(1, 12), $this->sequence++));
    }
}
