<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\Environment;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\FeatureFlags\ProductionGuard;

#[CoversClass(ProductionGuard::class)]
#[CoversClass(InMemoryFlags::class)]
final class ProductionGuardTest extends TestCase
{
    #[Test]
    public function chaos_and_lab_flags_are_off_in_production_whatever_the_flag_server_says(): void
    {
        $server = new InMemoryFlags([
            'chaos.enabled' => true,
            'chaos.commerce.payment-gateway-latency-ms' => 3000,
            'labs.enabled' => true,
        ]);
        $flags = new ProductionGuard($server, Environment::Production);

        self::assertFalse($flags->enabled('chaos.enabled'));
        self::assertSame(0, $flags->integer('chaos.commerce.payment-gateway-latency-ms', 0));
        self::assertFalse($flags->enabled('labs.enabled'));
        self::assertSame(0, $server->readsOf('chaos.enabled'), 'restricted flags are not even evaluated');
    }

    #[Test]
    public function product_flags_pass_through_in_production(): void
    {
        $flags = new ProductionGuard(new InMemoryFlags(['checkout.express-shipping' => true]), Environment::Production);

        self::assertTrue($flags->enabled('checkout.express-shipping'));
    }

    #[Test]
    public function chaos_flags_work_outside_production(): void
    {
        $flags = new ProductionGuard(new InMemoryFlags(['chaos.logistics.label-failure-rate' => 0.2]), Environment::Staging);

        self::assertSame(0.2, $flags->decimal('chaos.logistics.label-failure-rate', 0.0));
    }
}
