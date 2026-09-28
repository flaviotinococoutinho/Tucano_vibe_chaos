<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shipping\Adapter\Driving\Kafka\OrderEventHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Kafka\RetryPolicy;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:order-intake', description: 'Create and cancel shipments from commerce.orders (v1 and v2) until SIGTERM')]
final class OrderIntake extends Command
{
    private const string GROUP = 'logistics.order-intake';

    public function handle(OrderEventHandler $handler, Producer $deadLetters, LoggerInterface $logger): int
    {
        $consumer = new RdKafkaConsumer((string) config('messaging.brokers'), self::GROUP, ['commerce.orders.v1', 'commerce.orders.v2'], $deadLetters, $logger, self::patience());
        $consumer->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }

    /**
     * A paid order can arrive before the catalog copy has its products, above
     * all when this worker and logistics:sync-catalog start together. Up to
     * half a minute of retries outlasts the catalog consumer joining its group,
     * so those orders do not end up in the dead letter topic.
     */
    private static function patience(): RetryPolicy
    {
        return new RetryPolicy(maxAttempts: 8, baseDelayMs: 500, maxDelayMs: 10_000);
    }
}
