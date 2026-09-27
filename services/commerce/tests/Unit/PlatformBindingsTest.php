<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\SnowflakeGenerator;

final class PlatformBindingsTest extends TestCase
{
    #[Test]
    public function snowflakes_carry_the_configured_node(): void
    {
        config(['platform.snowflake.datacenter' => 1, 'platform.snowflake.worker' => 1]);

        $snowflake = $this->app->make(SnowflakeGenerator::class)->next();

        self::assertEquals(new NodeId(1, 1), $snowflake->node());
    }

    #[Test]
    public function chaos_flags_stay_off_when_the_environment_is_unknown(): void
    {
        $flags = $this->app->make(FeatureFlags::class);

        self::assertFalse($flags->enabled('chaos.enabled', false));
    }
}
