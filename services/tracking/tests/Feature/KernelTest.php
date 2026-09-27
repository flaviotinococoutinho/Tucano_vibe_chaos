<?php

declare(strict_types=1);

namespace Tests\Feature;

use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\InCoroutine;
use Tracking\Platform\Http\Kernel;
use Tracking\Platform\Http\Request;
use Tracking\Platform\Http\Response;
use Tracking\Platform\Http\Route;
use Tracking\Platform\Http\Router;
use Tracking\Platform\Logging\CorrelationIdProcessor;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Domain\ErrorCategory;

final class KernelTest extends TestCase
{
    private TestHandler $logs;
    private Kernel $kernel;

    protected function setUp(): void
    {
        $this->logs = new TestHandler(Level::Debug);
        $router = new Router(
            new Route('GET', '/health/live', static fn(): Response => Response::json(['status' => 'up'])),
            new Route('GET', '/test/conflict', static fn(): never => throw new class ('Courier 42 is already on a delivery.') extends DomainError {
                public function category(): ErrorCategory
                {
                    return ErrorCategory::Conflict;
                }
            }),
            new Route('GET', '/test/crash', static fn(): never => throw new RuntimeException('redis password is hunter2')),
        );
        $this->kernel = new Kernel($router, new Logger('tracking', [$this->logs], [new CorrelationIdProcessor()]));
    }

    #[Test]
    public function it_keeps_the_correlation_id_that_came_from_the_gateway(): void
    {
        $response = $this->handle('/health/live', '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12');

        self::assertSame(200, $response->status);
        self::assertSame('4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12', $response->headers['X-Correlation-Id']);
    }

    #[Test]
    public function it_creates_one_when_the_request_has_none(): void
    {
        $response = $this->handle('/health/live');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-/', $response->headers['X-Correlation-Id']);
    }

    #[Test]
    public function errors_are_problems_that_carry_the_correlation_id(): void
    {
        $response = $this->handle('/v1/nothing-here', 'req-1#1');

        self::assertSame(404, $response->status);
        self::assertSame('application/problem+json', $response->headers['Content-Type']);
        self::assertSame('req-1#1', $response->headers['X-Correlation-Id']);
        self::assertStringContainsString('"correlationId":"req-1#1"', $response->body);
    }

    #[Test]
    public function a_crash_is_logged_with_the_correlation_id_and_hidden_from_the_client(): void
    {
        $response = $this->handle('/test/crash', 'req-2#1');

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('hunter2', $response->body);
        self::assertTrue($this->logs->hasErrorThatContains('hunter2'));
        self::assertSame('req-2#1', $this->logs->getRecords()[0]->extra['correlation_id']);
    }

    #[Test]
    public function answers_the_domain_expects_are_not_incidents(): void
    {
        self::assertSame(409, $this->handle('/test/conflict')->status);
        self::assertSame(404, $this->handle('/v1/nothing-here')->status);

        self::assertFalse($this->logs->hasErrorRecords());
    }

    #[Test]
    public function head_gets_the_headers_of_get_without_the_body(): void
    {
        $response = $this->handle('/health/live', 'req-4#1', 'HEAD');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame((string) strlen('{"status":"up"}'), $response->headers['Content-Length']);
        self::assertSame('req-4#1', $response->headers['X-Correlation-Id']);
    }

    #[Test]
    public function every_request_leaves_a_debug_line_with_its_correlation_id(): void
    {
        $this->handle('/health/live', 'req-3#1');

        $record = $this->logs->getRecords()[0];
        self::assertSame('Request handled', $record->message);
        self::assertSame(['method' => 'GET', 'path' => '/health/live', 'status' => 200], array_intersect_key($record->context, ['method' => 0, 'path' => 0, 'status' => 0]));
        self::assertSame('req-3#1', $record->extra['correlation_id']);
    }

    private function handle(string $path, ?string $correlationId = null, string $method = 'GET'): Response
    {
        $request = new Request($method, $path, headers: $correlationId === null ? [] : ['X-Correlation-Id' => $correlationId]);

        return InCoroutine::run(fn(): Response => $this->kernel->handle($request));
    }
}
