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

        foreach (['logistics:relay-outbox', 'logistics:sync-catalog', 'logistics:order-intake'] as $worker) {
            self::assertArrayHasKey($worker, $commands);
        }
    }
}
