<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Needs the real MySQL and Redis (CI service containers or the compose stack). */
#[Group('integration')]
final class ReadinessIntegrationTest extends TestCase
{
    #[Test]
    public function the_service_is_ready_when_mysql_and_redis_answer(): void
    {
        $this->json('GET', '/health/ready');

        $this->response->assertOk()
            ->assertJsonPath('checks.database.status', 'up')
            ->assertJsonPath('checks.redis.status', 'up');
    }
}
