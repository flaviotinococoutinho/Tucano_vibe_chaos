<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The compose file starts these commands by name; a rename would stop a worker without a failed build. */
final class WorkersTest extends TestCase
{
    #[Test]
    public function every_worker_is_an_artisan_command(): void
    {
        $commands = Artisan::all();

        $workers = [
            'commerce:relay-outbox', 'commerce:sync-catalog', 'commerce:sync-shipments', 'commerce:expire-orders',
            'commerce:reconcile-payments', 'commerce:project-order-views',
        ];
        foreach ($workers as $worker) {
            self::assertArrayHasKey($worker, $commands);
        }
    }
}
