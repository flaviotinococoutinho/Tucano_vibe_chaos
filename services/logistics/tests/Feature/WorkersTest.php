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
            'logistics:relay-outbox', 'logistics:sync-catalog', 'logistics:order-intake', 'logistics:request-labels', 'logistics:book-pickups',
            'logistics:project-timelines', 'logistics:update-tracking-pages', 'logistics:reconcile-journeys', 'logistics:watch-stalled-journeys',
        ];
        foreach ($workers as $worker) {
            self::assertArrayHasKey($worker, $commands);
        }
    }
}
