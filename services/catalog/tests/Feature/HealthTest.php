<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Health\Readiness;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\StubCheck;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    #[Test]
    public function liveness_only_says_the_process_is_up(): void
    {
        $this->json('GET', '/health/live');

        $this->response->assertOk()->assertExactJson(['status' => 'up']);
    }

    #[Test]
    public function readiness_reports_every_dependency(): void
    {
        $this->app->instance(Readiness::class, new Readiness([StubCheck::up('database'), StubCheck::up('redis')]));

        $this->json('GET', '/health/ready');

        $this->response->assertOk()
            ->assertJsonPath('status', 'up')
            ->assertJsonPath('checks.database.status', 'up')
            ->assertJsonPath('checks.redis.status', 'up');
    }

    #[Test]
    public function readiness_fails_when_one_dependency_is_down(): void
    {
        $this->app->instance(Readiness::class, new Readiness([
            StubCheck::up('database'),
            StubCheck::down('redis', 'Connection refused'),
        ]));

        $this->json('GET', '/health/ready');

        $this->response->assertServiceUnavailable()
            ->assertJsonPath('status', 'down')
            ->assertJsonPath('checks.database.status', 'up')
            ->assertJsonPath('checks.redis.status', 'down')
            ->assertJsonPath('checks.redis.error', 'Connection refused');
    }
}
