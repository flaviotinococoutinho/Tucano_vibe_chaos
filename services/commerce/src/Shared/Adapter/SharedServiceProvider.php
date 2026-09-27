<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter;

use Commerce\Shared\Adapter\Driven\LaravelTransactions;
use Commerce\Shared\Adapter\Driven\OutboxEvents;
use Commerce\Shared\Adapter\Driven\PostgresRequestMemory;
use Commerce\Shared\Adapter\Driving\Console\RelayOutbox;
use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Commerce\Shared\Application\Port\Driven\ForRememberingRequests;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Illuminate\Support\ServiceProvider;
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
        ForRememberingRequests::class => PostgresRequestMemory::class,
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
