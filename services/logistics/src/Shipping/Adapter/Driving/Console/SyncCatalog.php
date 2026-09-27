<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shipping\Adapter\Driving\Kafka\CatalogSnapshotHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:sync-catalog', description: 'Keep weights and sizes in step with catalog.products.v1 until SIGTERM')]
final class SyncCatalog extends Command
{
    private const string GROUP = 'logistics.catalog-sync';

    public function handle(CatalogSnapshotHandler $handler, Producer $deadLetters, LoggerInterface $logger): int
    {
        $consumer = new RdKafkaConsumer((string) config('messaging.brokers'), self::GROUP, ['catalog.products.v1'], $deadLetters, $logger);
        $consumer->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
