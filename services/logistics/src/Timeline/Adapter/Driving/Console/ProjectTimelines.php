<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Logistics\Timeline\Adapter\Driving\Kafka\TimelineProjector;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:project-timelines', description: 'Keep the shipment timelines and the tracking pages from logistics.shipments.v2 until SIGTERM')]
final class ProjectTimelines extends Command
{
    private const string GROUP = 'logistics.timeline-projector';

    public function handle(TimelineProjector $projector, KafkaConsumers $consumers): int
    {
        $consumers->subscribe(self::GROUP, ['logistics.shipments.v2'])->run($projector, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
