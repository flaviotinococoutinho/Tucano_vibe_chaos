<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Timeline\Adapter\Driving\Kafka\TimelineProjector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:project-timelines', description: 'Keep the shipment timelines and the tracking pages from logistics.shipments.v2 until SIGTERM')]
final class ProjectTimelines extends Command
{
    private const string GROUP = 'logistics.timeline-projector';

    public function handle(TimelineProjector $projector, Producer $deadLetters, LoggerInterface $logger): int
    {
        $consumer = new RdKafkaConsumer((string) config('messaging.brokers'), self::GROUP, ['logistics.shipments.v2'], $deadLetters, $logger);
        $consumer->run($projector, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
