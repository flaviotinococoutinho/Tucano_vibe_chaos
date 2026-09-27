<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\StubCheck;
use Tracking\Platform\Health\HealthCheck;
use Tracking\Platform\Health\HealthController;
use Tracking\Platform\Health\Readiness;

final class HealthControllerTest extends TestCase
{
    private TestHandler $logs;

    protected function setUp(): void
    {
        $this->logs = new TestHandler();
    }

    #[Test]
    public function liveness_only_says_the_process_is_up(): void
    {
        $response = $this->controller(StubCheck::down('redis', 'Connection refused'))->live();

        self::assertSame(200, $response->status);
        self::assertSame('{"status":"up"}', $response->body);
    }

    #[Test]
    public function readiness_reports_every_dependency(): void
    {
        $response = $this->controller(StubCheck::up('redis'))->ready();

        self::assertSame(200, $response->status);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertStringStartsWith('{"status":"up","checks":{"redis":{"status":"up","latencyMs":', $response->body);
        self::assertFalse($this->logs->hasWarningRecords());
    }

    #[Test]
    public function readiness_fails_when_one_dependency_is_down(): void
    {
        $response = $this->controller(StubCheck::up('flagd'), StubCheck::down('redis', 'Connection refused'))->ready();

        self::assertSame(503, $response->status);
        self::assertStringContainsString('"redis":{"status":"down"', $response->body);
        self::assertStringContainsString('"error":"Connection refused"', $response->body);
    }

    #[Test]
    public function a_failed_readiness_leaves_the_reason_in_the_log(): void
    {
        $this->controller(StubCheck::down('redis', 'Connection refused'))->ready();

        self::assertTrue($this->logs->hasWarning('Not ready'));
        $checks = $this->logs->getRecords()[0]->context['checks'];
        self::assertIsArray($checks);
        self::assertSame('Connection refused', $checks['redis']['error'] ?? null);
    }

    private function controller(HealthCheck ...$checks): HealthController
    {
        return new HealthController(new Readiness($checks), new Logger('tracking', [$this->logs]));
    }
}
