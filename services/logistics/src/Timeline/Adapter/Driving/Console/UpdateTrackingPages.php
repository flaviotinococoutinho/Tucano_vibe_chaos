<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Logistics\Timeline\Adapter\Driving\Kafka\TrackingPageProjector;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Worker\StopSignal;

/**
 * The public page reads the events in a consumer group of its own, apart from the internal
 * timeline: an outage of MongoDB never holds a step back from the page, and an outage of
 * DynamoDB never holds one back from the timeline (ADR 0027).
 */
#[AsCommand(name: 'logistics:update-tracking-pages', description: 'Keep the public tracking pages from logistics.shipments.v2 until SIGTERM')]
final class UpdateTrackingPages extends Command
{
    private const string GROUP = 'logistics.tracking-pages';

    public function handle(TrackingPageProjector $projector, KafkaConsumers $consumers): int
    {
        $consumers->subscribe(self::GROUP, ['logistics.shipments.v2'], StoreOutOfReach::dynamo(...))->run($projector, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
