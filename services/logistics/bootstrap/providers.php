<?php

declare(strict_types=1);

return [
    App\Providers\PlatformServiceProvider::class,
    Logistics\Shared\Adapter\SharedServiceProvider::class,
    Logistics\CarrierSelection\Adapter\CarrierSelectionServiceProvider::class,
    Logistics\Shipping\Adapter\ShippingServiceProvider::class,
    Logistics\Timeline\Adapter\TimelineServiceProvider::class,
];
