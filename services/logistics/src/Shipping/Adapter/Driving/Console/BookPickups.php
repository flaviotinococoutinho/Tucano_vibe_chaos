<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shipping\Adapter\Driving\Kafka\PickupBookingHandler;
use Logistics\Shipping\Domain\Error\PickupNotBooked;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Kafka\LostConnection;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Kafka\RetryPolicy;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:book-pickups', description: 'Book the pickup of every shipment ready for it, until SIGTERM')]
final class BookPickups extends Command
{
    private const string GROUP = 'logistics.pickup-bookings';

    public function handle(PickupBookingHandler $handler, Producer $deadLetters, LoggerInterface $logger): int
    {
        $consumer = new RdKafkaConsumer((string) config('messaging.brokers'), self::GROUP, ['logistics.shipments.v2'], $deadLetters, $logger, self::patience());
        $consumer->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }

    /** A carrier down stops every booking the same way: the partition waits for it (ADR 0017). */
    private static function patience(): RetryPolicy
    {
        return new RetryPolicy(unlimited: static fn(Throwable $failure): bool => $failure instanceof PickupNotBooked || LostConnection::causedBy($failure));
    }
}
