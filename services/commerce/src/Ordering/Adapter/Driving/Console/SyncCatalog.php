<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Console;

use Commerce\Ordering\Adapter\Driving\Kafka\CatalogSnapshotHandler;
use Commerce\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'commerce:sync-catalog', description: 'Keep the local catalog copy in step with catalog.products.v1 until SIGTERM')]
final class SyncCatalog extends Command
{
    private const string GROUP = 'commerce.catalog-sync';

    public function handle(CatalogSnapshotHandler $handler, KafkaConsumers $consumers): int
    {
        $consumers->subscribe(self::GROUP, ['catalog.products.v1'])->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
