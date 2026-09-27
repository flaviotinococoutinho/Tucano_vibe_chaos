<?php

declare(strict_types=1);

return [
    App\Providers\PlatformServiceProvider::class,
    Commerce\Shared\Adapter\SharedServiceProvider::class,
    Commerce\Inventory\Adapter\InventoryServiceProvider::class,
    Commerce\Ordering\Adapter\OrderingServiceProvider::class,
];
