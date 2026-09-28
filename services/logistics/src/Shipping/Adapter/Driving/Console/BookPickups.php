<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Logistics\Shipping\Adapter\Driving\Kafka\PickupBookingHandler;
use Logistics\Shipping\Domain\Error\PickupNotBooked;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Kafka\LostConnection;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:book-pickups', description: 'Book the pickup of every shipment ready for it, until SIGTERM')]
final class BookPickups extends Command
{
    private const string GROUP = 'logistics.pickup-bookings';

    public function handle(PickupBookingHandler $handler, KafkaConsumers $consumers): int
    {
        $consumers->subscribe(self::GROUP, ['logistics.shipments.v2'], self::waitsWithoutLimit(...))->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }

    /** A carrier down stops every booking the same way: the partition waits for it (ADR 0017). */
    private static function waitsWithoutLimit(Throwable $failure): bool
    {
        return $failure instanceof PickupNotBooked || LostConnection::causedBy($failure);
    }
}
