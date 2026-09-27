<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter;

use Commerce\Shared\Adapter\Driven\LaravelTransactions;
use Commerce\Shared\Adapter\Driven\OutboxEvents;
use Commerce\Shared\Adapter\Driven\PostgresRequestMemory;
use Commerce\Shared\Application\Port\Driven\ForPublishingEvents;
use Commerce\Shared\Application\Port\Driven\ForRememberingRequests;
use Commerce\Shared\Application\Port\Driven\ForRunningTransactions;
use Illuminate\Support\ServiceProvider;

/** Plugs the ports every feature package shares into their adapters. */
final class SharedServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForRunningTransactions::class => LaravelTransactions::class,
        ForPublishingEvents::class => OutboxEvents::class,
        ForRememberingRequests::class => PostgresRequestMemory::class,
    ];
}
