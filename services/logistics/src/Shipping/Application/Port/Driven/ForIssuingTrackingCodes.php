<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Domain\Shipment\TrackingCode;

interface ForIssuingTrackingCodes
{
    public function next(): TrackingCode;
}
