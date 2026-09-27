<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Console;

use Commerce\Ordering\Adapter\Driving\Kafka\CatalogSnapshotHandler;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'commerce:sync-catalog', description: 'Keep the local catalog copy in step with catalog.products.v1 until SIGTERM')]
final class SyncCatalog extends Command
{
    private const string GROUP = 'commerce.catalog-sync';

    public function handle(CatalogSnapshotHandler $handler, Producer $deadLetters, LoggerInterface $logger): int
    {
        $consumer = new RdKafkaConsumer((string) config('messaging.brokers'), self::GROUP, ['catalog.products.v1'], $deadLetters, $logger);
        $consumer->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
