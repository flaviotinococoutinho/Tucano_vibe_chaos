<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\InCoroutine;
use Tracking\Platform\CompositionRoot;
use Tracking\Platform\Config;
use Tracking\Platform\Http\Request;
use Tracking\Platform\Http\Response;
use Tucano\FeatureFlags\ProductionGuard;

final class CompositionRootTest extends TestCase
{
    #[Test]
    #[DataProvider('flagDrivers')]
    public function feature_flags_always_pass_through_the_production_guard(string $driver): void
    {
        $root = CompositionRoot::boot(Config::fromEnvironment(['FLAGS_DRIVER' => $driver]), new NullLogger());

        self::assertInstanceOf(ProductionGuard::class, $root->featureFlags);
        self::assertFalse($root->featureFlags->enabled('chaos.enabled', false));
    }

    /** @return iterable<string, array{string}> */
    public static function flagDrivers(): iterable
    {
        yield 'flagd' => ['flagd'];
        yield 'memory' => ['memory'];
    }

    #[Test]
    public function the_liveness_endpoint_is_routed(): void
    {
        $kernel = CompositionRoot::boot(Config::fromEnvironment(['FLAGS_DRIVER' => 'memory']), new NullLogger())->kernel;

        $response = InCoroutine::run(static fn(): Response => $kernel->handle(new Request('GET', '/health/live')));

        self::assertSame(200, $response->status);
        self::assertSame('{"status":"up"}', $response->body);
    }

    #[Test]
    public function the_live_delivery_routes_are_wired(): void
    {
        $kernel = CompositionRoot::boot(Config::fromEnvironment(['FLAGS_DRIVER' => 'memory']), new NullLogger())->kernel;

        [$report, $follow] = InCoroutine::run(static fn(): array => [
            $kernel->handle(new Request('POST', '/v1/positions', body: '{}')),
            $kernel->handle(new Request('GET', '/v1/live', 'trackingCode=TX02Q6AGJQ45G00')),
        ]);

        self::assertSame(401, $report->status, 'an unsigned report never reaches Redis');
        self::assertSame(426, $follow->status, 'the live route speaks WebSocket only');
    }
}
