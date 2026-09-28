<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Logistics\Shipping\Application\Port\Driving\ForDispatchingDeliveries;
use Logistics\Shipping\Application\Port\Driving\ForRecordingDeliveryOutcomes;
use Logistics\Shipping\Application\Port\Driving\ForRecordingHubScans;
use Logistics\Shipping\Application\Port\Driving\ForRecordingPickups;
use Logistics\Shipping\Application\Port\Driving\ForReturningToSender;

/** The use cases a carrier event lands on, UC-SHP-04 to 08, together for whoever holds carrier events: the webhook and the reconciliation. */
final readonly class CarrierJourney
{
    public function __construct(
        public ForRecordingPickups $pickups,
        public ForRecordingHubScans $hubScans,
        public ForDispatchingDeliveries $dispatches,
        public ForRecordingDeliveryOutcomes $visits,
        public ForReturningToSender $returns,
    ) {}
}
