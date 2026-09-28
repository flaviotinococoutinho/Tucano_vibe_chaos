<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Console;

use Commerce\Ordering\Adapter\Driving\Kafka\ShipmentEventHandler;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Worker\StopSignal;

/** UC-ORD-04 from the log. The default retry policy already waits for a database that went away (ADR 0017). */
#[AsCommand(name: 'commerce:sync-shipments', description: 'Let orders follow their shipments from logistics.shipments.v2 until SIGTERM')]
final class SyncShipments extends Command
{
    private const string GROUP = 'commerce.shipment-sync';

    public function handle(ShipmentEventHandler $handler, Producer $deadLetters, LoggerInterface $logger): int
    {
        $consumer = new RdKafkaConsumer((string) config('messaging.brokers'), self::GROUP, ['logistics.shipments.v2'], $deadLetters, $logger);
        $consumer->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
