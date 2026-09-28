<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Console;

use Commerce\Ordering\Adapter\Driving\Kafka\ShipmentEventHandler;
use Commerce\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Worker\StopSignal;

/** UC-ORD-04 from the log. The default retry policy already waits for a database that went away (ADR 0017). */
#[AsCommand(name: 'commerce:sync-shipments', description: 'Let orders follow their shipments from logistics.shipments.v2 until SIGTERM')]
final class SyncShipments extends Command
{
    private const string GROUP = 'commerce.shipment-sync';

    public function handle(ShipmentEventHandler $handler, KafkaConsumers $consumers): int
    {
        $consumers->subscribe(self::GROUP, ['logistics.shipments.v2'])->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
