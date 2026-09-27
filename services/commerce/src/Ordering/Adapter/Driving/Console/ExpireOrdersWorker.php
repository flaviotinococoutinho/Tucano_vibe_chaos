<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Console;

use Commerce\Ordering\Application\Port\Driving\ForExpiringOrders;
use Illuminate\Console\Command;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;
use Tucano\Messaging\Worker\StopSignal;

/**
 * Cancels unpaid orders whose reservation ran out, until SIGTERM. Several
 * copies can run side by side: FOR UPDATE SKIP LOCKED gives each order to one
 * of them. This loop is the clock, the actor of UC-ORD-03.
 */
#[AsCommand(name: 'commerce:expire-orders', description: 'Cancel unpaid orders whose reservation ran out, until SIGTERM')]
final class ExpireOrdersWorker extends Command
{
    private const int IDLE_MILLISECONDS = 5_000;

    private const int FAILURE_MILLISECONDS = 2_000;

    public function handle(ForExpiringOrders $orders, LoggerInterface $logger): int
    {
        $stop = StopSignal::onTermination();
        $logger->info('order expiry started');
        while (!$stop->requested()) {
            try {
                $expired = $orders->expireNext();
            } catch (Throwable $failure) {
                $logger->error('Order expiry failed: {message}', ['message' => $failure->getMessage(), 'exception' => $failure]);
                $stop->pause(self::FAILURE_MILLISECONDS);

                continue;
            }
            if ($expired === null) {
                $stop->pause(self::IDLE_MILLISECONDS);

                continue;
            }
            $logger->info('Order {orderId} expired and released its stock', ['orderId' => $expired->toString()]);
        }
        $logger->info('order expiry stopped');

        return self::SUCCESS;
    }
}
