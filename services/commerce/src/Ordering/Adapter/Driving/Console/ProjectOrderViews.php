<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Console;

use Commerce\Ordering\Adapter\Driving\Kafka\OrderViewProjector;
use Commerce\Shared\Adapter\Driving\Kafka\KafkaConsumers;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\Messaging\Worker\StopSignal;

/**
 * UC-ORD-08 from the log. The customer's order list reads commerce.orders.v2 in a consumer
 * group of its own (ADR 0027), and waits without a limit while MongoDB is out of reach. The
 * chaos flag takes the projector out of its group: the list falls behind the orders, on
 * purpose, and catches up from where it stopped once the flag is off (ADR 0012).
 */
#[AsCommand(name: 'commerce:project-order-views', description: 'Keep the customer order list (order_views) from commerce.orders.v2 until SIGTERM')]
final class ProjectOrderViews extends Command
{
    private const string GROUP = 'commerce.order-projector';

    private const string PAUSED = 'chaos.commerce.order-projector-paused';

    public function handle(OrderViewProjector $projector, KafkaConsumers $consumers, FeatureFlags $flags): int
    {
        $consumers->subscribe(self::GROUP, ['commerce.orders.v2'], StoreOutOfReach::mongo(...))
            ->run($projector, StopSignal::onTermination(), static fn(): bool => $flags->enabled(self::PAUSED));

        return self::SUCCESS;
    }
}
