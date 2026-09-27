<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use PDO;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Outbox\OutboxRelay;
use Tucano\Messaging\Outbox\OutboxRelayWorker;
use Tucano\Messaging\Worker\StopSignal;

/**
 * Publishes outbox_messages to Kafka until SIGTERM. The chaos flag pauses it
 * without stopping the process: orders keep coming in, events pile up in the
 * outbox, and they all go out, in order, once the flag is off again.
 */
#[AsCommand(name: 'commerce:relay-outbox', description: 'Publish the outbox to Kafka until SIGTERM')]
final class RelayOutbox extends Command
{
    public function handle(Connection $database, Producer $producer, FeatureFlags $flags, LoggerInterface $logger): int
    {
        $worker = new OutboxRelayWorker(
            // A new connection each time the relay asks, which it does again after a failed
            // batch: a restart or a failover of the database leaves the old one dead.
            new OutboxRelay(static function () use ($database): PDO {
                $database->reconnect();

                return $database->getPdo();
            }, $producer),
            $logger,
            static fn(): bool => $flags->enabled('chaos.commerce.outbox-relay-paused'),
        );
        $worker->run(StopSignal::onTermination());

        return self::SUCCESS;
    }
}
