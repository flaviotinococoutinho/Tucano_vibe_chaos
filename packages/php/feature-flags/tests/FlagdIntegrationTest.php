<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\Cache\InMemoryFlagCache;
use Tucano\FeatureFlags\Environment;
use Tucano\FeatureFlags\FlagContext;
use Tucano\FeatureFlags\Flagd;
use Tucano\FeatureFlags\OpenFeatureFlags;

/** Runs against a real flagd serving infra/flags/environments/local.flagd.json. */
#[CoversClass(Flagd::class)]
#[CoversClass(OpenFeatureFlags::class)]
#[Group('integration')]
final class FlagdIntegrationTest extends TestCase
{
    private string $host;
    private int $port;

    protected function setUp(): void
    {
        $this->host = (string) (getenv('FLAGD_HOST') ?: '');
        $this->port = (int) (getenv('FLAGD_PORT') ?: 8013);
        if ($this->host === '') {
            self::markTestSkipped('Set FLAGD_HOST to run the flagd integration tests.');
        }
    }

    #[Test]
    public function it_reads_every_kind_of_flag_from_flagd(): void
    {
        $flags = Flagd::connect('feature-flags-tests', Environment::Local, $this->host, $this->port, new InMemoryFlagCache());

        self::assertTrue($flags->enabled('labs.enabled'));
        self::assertSame('atomic', $flags->text('inventory.reservation-strategy', 'pessimistic'));
        self::assertSame(0, $flags->integer('chaos.commerce.payment-gateway-latency-ms', 99));
        self::assertTrue($flags->enabled('checkout.express-shipping', false, FlagContext::forCustomer('customer-42')));
    }

    #[Test]
    public function unknown_flags_fall_back(): void
    {
        $flags = Flagd::connect('feature-flags-tests', Environment::Local, $this->host, $this->port, new InMemoryFlagCache());

        self::assertSame('fallback', $flags->text('does.not-exist', 'fallback'));
    }

    #[Test]
    public function an_unreachable_flag_server_falls_back_fast(): void
    {
        $flags = Flagd::connect('feature-flags-tests', Environment::Local, $this->host, 1, new InMemoryFlagCache());

        $startedAt = microtime(true);
        $enabled = $flags->enabled('labs.enabled', false);

        self::assertFalse($enabled);
        self::assertLessThan(1.0, microtime(true) - $startedAt);
    }
}
