<?php

declare(strict_types=1);

namespace Logistics\Shared\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Outbox\OutboxRelay;
use Tucano\Messaging\Outbox\OutboxRelayWorker;
use Tucano\Messaging\Worker\StopSignal;

/**
 * Publishes outbox_messages to Kafka until SIGTERM. The chaos flag pauses it
 * without stopping the process: shipments keep changing, events pile up in the
 * outbox, and they all go out, in order, once the flag is off again.
 */
#[AsCommand(name: 'logistics:relay-outbox', description: 'Publish the outbox to Kafka until SIGTERM')]
final class RelayOutbox extends Command
{
    public function handle(Connection $database, Producer $producer, FeatureFlags $flags, LoggerInterface $logger): int
    {
        $worker = new OutboxRelayWorker(
            new OutboxRelay($database->getPdo(), $producer),
            $logger,
            static fn(): bool => $flags->enabled('chaos.logistics.outbox-relay-paused'),
        );
        $worker->run(StopSignal::onTermination());

        return self::SUCCESS;
    }
}
