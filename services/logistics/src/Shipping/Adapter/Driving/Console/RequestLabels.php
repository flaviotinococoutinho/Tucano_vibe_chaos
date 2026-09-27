<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shipping\Adapter\Driving\Kafka\LabelRequestHandler;
use Logistics\Shipping\Domain\Error\LabelNotQueued;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Kafka\LostConnection;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Kafka\RetryPolicy;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:request-labels', description: 'Queue the label of every shipment created, until SIGTERM')]
final class RequestLabels extends Command
{
    private const string GROUP = 'logistics.label-requests';

    public function handle(LabelRequestHandler $handler, Producer $deadLetters, LoggerInterface $logger): int
    {
        $consumer = new RdKafkaConsumer((string) config('messaging.brokers'), self::GROUP, ['logistics.shipments.v1'], $deadLetters, $logger, self::patience());
        $consumer->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }

    /**
     * The queue down stops every request the same way, like a lost database: the
     * partition waits for it instead of filling the dead letter topic.
     */
    private static function patience(): RetryPolicy
    {
        return new RetryPolicy(unlimited: static fn(Throwable $failure): bool => $failure instanceof LabelNotQueued || LostConnection::causedBy($failure));
    }
}
