<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\SharedKernel\Time\Clock;

final class PlatformBindingsTest extends TestCase
{
    #[Test]
    public function the_clock_tells_utc_time(): void
    {
        $now = $this->app->make(Clock::class)->now();

        self::assertSame('UTC', $now->getTimezone()->getName());
    }

    #[Test]
    public function chaos_flags_stay_off_when_the_environment_is_unknown(): void
    {
        $this->app->instance(InMemoryFlags::class, new InMemoryFlags(['chaos.enabled' => true]));

        $flags = $this->app->make(FeatureFlags::class);

        self::assertFalse($flags->enabled('chaos.enabled'));
    }

    #[Test]
    public function chaos_flags_reach_the_code_in_the_local_environment(): void
    {
        config(['platform.environment' => 'local']);
        $this->app->instance(InMemoryFlags::class, new InMemoryFlags(['chaos.enabled' => true]));

        $flags = $this->app->make(FeatureFlags::class);

        self::assertTrue($flags->enabled('chaos.enabled'));
    }
}
