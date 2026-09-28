<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Logistics\Shipping\Adapter\Driving\Kafka\LabelRequestHandler;
use Logistics\Shipping\Domain\Error\LabelNotQueued;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Kafka\LostConnection;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:request-labels', description: 'Queue the label of every shipment created, until SIGTERM')]
final class RequestLabels extends Command
{
    private const string GROUP = 'logistics.label-requests';

    public function handle(LabelRequestHandler $handler, KafkaConsumers $consumers): int
    {
        $consumers->subscribe(self::GROUP, ['logistics.shipments.v2'], self::waitsWithoutLimit(...))->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }

    /**
     * The queue down stops every request the same way, like a lost database: the
     * partition waits for it instead of filling the dead letter topic.
     */
    private static function waitsWithoutLimit(Throwable $failure): bool
    {
        return $failure instanceof LabelNotQueued || LostConnection::causedBy($failure);
    }
}
