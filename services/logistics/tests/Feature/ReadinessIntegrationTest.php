<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\Blackhole;
use Tests\TestCase;

/** Needs the real PostgreSQL and Redis (CI service containers or the compose stack). */
#[Group('integration')]
final class ReadinessIntegrationTest extends TestCase
{
    /** Connect timeouts are 2 s for the database and 1 s for Redis; the rest is slack for slow CI runners. */
    private const float GIVES_UP_WITHIN_SECONDS = 4.0;

    #[Test]
    public function the_service_is_ready_when_postgres_and_redis_answer(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertJsonPath('checks.database.status', 'up')
            ->assertJsonPath('checks.redis.status', 'up');
    }

    #[Test]
    public function a_database_that_stops_answering_fails_the_check_in_seconds(): void
    {
        $blackhole = Blackhole::open();
        config([
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.port' => $blackhole->port(),
        ]);
        $this->app->make('db')->purge('pgsql');

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
        $response = $this->getJson('/health/ready');
        $seconds = (hrtime(true) - $started) / 1e9;

        $response->assertStatus(503)->assertJsonPath("checks.{$check}.status", 'down');
        self::assertLessThan(self::GIVES_UP_WITHIN_SECONDS, $seconds, "The {$check} check took {$seconds} s to give up.");
    }
}
