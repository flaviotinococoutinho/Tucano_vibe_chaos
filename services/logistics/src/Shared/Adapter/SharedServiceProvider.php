<?php

declare(strict_types=1);

namespace Logistics\Shared\Adapter;

use Illuminate\Support\ServiceProvider;
use Logistics\Shared\Adapter\Driven\LaravelTransactions;
use Logistics\Shared\Adapter\Driven\OutboxEvents;
use Logistics\Shared\Adapter\Driven\PostgresInbox;
use Logistics\Shared\Adapter\Driving\Console\RelayOutbox;
use Logistics\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Logistics\Shared\Application\Port\Driven\ForPublishingEvents;
use Logistics\Shared\Application\Port\Driven\ForRunningTransactions;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaProducer;

/** Plugs the ports every feature package shares into their adapters, plus the Kafka producer and the outbox relay. */
final class SharedServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForRunningTransactions::class => LaravelTransactions::class,
        ForPublishingEvents::class => OutboxEvents::class,
        ForDeduplicatingMessages::class => PostgresInbox::class,
    ];

    public function register(): void
    {
        // One producer per process: it keeps its connections and batches messages across calls.
        $this->app->singleton(Producer::class, fn(): Producer => new RdKafkaProducer(
            (string) config('messaging.brokers'),
            (string) config('messaging.client_id'),
            logger: $this->app->make(LoggerInterface::class),
        ));
    }

    public function boot(): void
    {
        $this->commands([RelayOutbox::class]);
    }
}
