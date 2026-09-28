<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Console;

use Illuminate\Console\Command;
use Logistics\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Logistics\Shipping\Adapter\Driving\Kafka\OrderEventHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\Messaging\Worker\StopSignal;

#[AsCommand(name: 'logistics:order-intake', description: 'Create and cancel shipments from commerce.orders.v2 until SIGTERM')]
final class OrderIntake extends Command
{
    private const string GROUP = 'logistics.order-intake';

    public function handle(OrderEventHandler $handler, KafkaConsumers $consumers): int
    {
        // A paid order can arrive before the catalog copy has its products: the intake waits longer (config/messaging.php).
        $consumers->subscribe(self::GROUP, ['commerce.orders.v2'], retry: 'messaging.order_intake.retry')->run($handler, StopSignal::onTermination());

        return self::SUCCESS;
    }
}
