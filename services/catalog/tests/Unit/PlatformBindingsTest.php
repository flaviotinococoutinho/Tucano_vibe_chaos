<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Messaging\LazyProducer;
use Illuminate\Contracts\Cache\LockProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\Messaging\Kafka\Producer;
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

    #[Test]
    public function the_kafka_producer_waits_for_the_first_message(): void
    {
        // Drops the in-memory producer every test gets, to see what the service really binds.
        $this->app->forgetInstance(Producer::class);

        $producer = $this->app->make(Producer::class);
        $producer->flush();

        self::assertInstanceOf(LazyProducer::class, $producer);
    }

    #[Test]
    public function cache_locks_come_from_the_cache_store(): void
    {
        $locks = $this->app->make(LockProvider::class);

        self::assertSame($this->app->make('cache.store')->getStore(), $locks);
    }
}
