<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Logistics\Shipping\Adapter\Driving\Kafka\CatalogSnapshotHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:sync-catalog', description: 'Keep weights and sizes in step with catalog.products.v1 until SIGTERM')]
final class SyncCatalog extends Command
{
    private const string GROUP = 'logistics.catalog-sync';

    public function handle(CatalogSnapshotHandler $handler, KafkaConsumers $consumers): int
    {
        $consumers->subscribe(self::GROUP, ['catalog.products.v1'])->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
