<?php

declare(strict_types=1);

namespace Tests\Unit;

use Logistics\Shipping\Application\Port\Driven\ForIssuingTrackingCodes;
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
        config(['platform.snowflake.datacenter' => 1, 'platform.snowflake.worker' => 11]);

        $snowflake = $this->app->make(SnowflakeGenerator::class)->next();

        self::assertEquals(new NodeId(1, 11), $snowflake->node());
    }

    #[Test]
    public function tracking_codes_tell_which_process_created_them(): void
    {
        // The order intake worker runs with SNOWFLAKE_WORKER_ID=12; PHP-FPM keeps the default 11.
        config(['platform.snowflake.datacenter' => 1, 'platform.snowflake.worker' => 12]);

        $code = $this->app->make(ForIssuingTrackingCodes::class)->next();

        self::assertEquals(new NodeId(1, 12), $code->snowflake->node());
        self::assertMatchesRegularExpression('/^TX[0-9A-HJKMNP-TV-Z]{13}$/', (string) $code);
    }

    #[Test]
    public function chaos_flags_stay_off_when_the_environment_is_unknown(): void
    {
        $flags = $this->app->make(FeatureFlags::class);

        self::assertFalse($flags->enabled('chaos.enabled', false));
    }
}
