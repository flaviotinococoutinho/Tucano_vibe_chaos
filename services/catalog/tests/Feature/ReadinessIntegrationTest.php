<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\Blackhole;
use Tests\TestCase;

/** Needs the real MySQL and Redis (CI service containers or the compose stack). */
#[Group('integration')]
final class ReadinessIntegrationTest extends TestCase
{
    /**
     * The MySQL connector tries twice with a 2 s read timeout and Redis gives up after 1 s.
     * The rest is slack for slow CI runners.
     */
    private const float GIVES_UP_WITHIN_SECONDS = 6.0;

    #[Test]
    public function the_service_is_ready_when_mysql_and_redis_answer(): void
    {
        $this->json('GET', '/health/ready');

        $this->response->assertOk()
            ->assertJsonPath('checks.database.status', 'up')
            ->assertJsonPath('checks.redis.status', 'up');
    }

    #[Test]
    public function a_database_that_stops_answering_fails_the_check_in_seconds(): void
    {
        $blackhole = Blackhole::open();
        config([
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => $blackhole->port(),
        ]);
        $this->app->make('db')->purge('mysql');

        $this->assertReadinessGivesUpQuickly('database');
    }

    #[Test]
    public function a_redis_that_stops_answering_fails_the_check_in_seconds(): void
    {
        $blackhole = Blackhole::open();
        config([
            'database.redis.default.host' => '127.0.0.1',
            'database.redis.default.port' => $blackhole->port(),
        ]);
        $this->app->forgetInstance('redis');

        $this->assertReadinessGivesUpQuickly('redis');
    }

    private function assertReadinessGivesUpQuickly(string $check): void
    {
        $started = hrtime(true);
        $this->json('GET', '/health/ready');
        $seconds = (hrtime(true) - $started) / 1e9;

        $this->response->assertStatus(503)->assertJsonPath("checks.{$check}.status", 'down');
        self::assertLessThan(self::GIVES_UP_WITHIN_SECONDS, $seconds, "The {$check} check took {$seconds} s to give up.");
    }
}
