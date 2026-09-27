<?php

declare(strict_types=1);

namespace App\Providers;

use App\Health\DatabaseCheck;
use App\Health\HealthCheck;
use App\Health\Readiness;
use App\Health\RedisCheck;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\ServiceProvider;
use MongoDB\Client;
use MongoDB\Database;
use Tucano\FeatureFlags\Cache\ApcuFlagCache;
use Tucano\FeatureFlags\Cache\FlagCache;
use Tucano\FeatureFlags\Cache\InMemoryFlagCache;
use Tucano\FeatureFlags\Environment;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\Flagd;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\FeatureFlags\ProductionGuard;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Sequence\ApcuSequence;
use Tucano\SharedKernel\Identity\Snowflake\Sequence\InMemorySequence;
use Tucano\SharedKernel\Identity\Snowflake\Sequence\SequenceProvider;
use Tucano\SharedKernel\Identity\Snowflake\SnowflakeGenerator;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\SystemClock;

/**
 * Wires the platform pieces every feature relies on: clock, ids, feature
 * flags, the read model database and health checks. The runtime decides some of them: PHP-FPM shares
 * APCu between its children, while a CLI worker is a single long-lived process.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(SnowflakeGenerator::class, fn(): SnowflakeGenerator => new SnowflakeGenerator(
            $this->nodeId(),
            $this->sequence(),
            $this->app->make(Clock::class),
        ));
        $this->app->singleton(FeatureFlags::class, fn(): FeatureFlags => $this->featureFlags());
        $this->app->singleton(Database::class, fn(): Database => (new Client(
            (string) config('read_models.uri'),
            (array) config('read_models.options'),
        ))->selectDatabase((string) config('read_models.database')));

        $this->app->tag([DatabaseCheck::class, RedisCheck::class], HealthCheck::TAG);
        $this->app->when(Readiness::class)->needs('$checks')->giveTagged(HealthCheck::TAG);
    }

    public function boot(): void
    {
        Context::add('service', config('platform.service'));
    }

    private function nodeId(): NodeId
    {
        return new NodeId(
            (int) config('platform.snowflake.datacenter'),
            (int) config('platform.snowflake.worker'),
        );
    }

    private function sequence(): SequenceProvider
    {
        if (!self::isFpm()) {
            return new InMemorySequence();
        }
        $node = $this->nodeId();

        return new ApcuSequence(sprintf('snowflake:%d:%d', $node->datacenter, $node->worker));
    }

    private function featureFlags(): FeatureFlags
    {
        $environment = Environment::fromName((string) config('platform.environment'));
        if (config('platform.flags.driver') === 'memory') {
            return new ProductionGuard(new InMemoryFlags(), $environment);
        }

        return Flagd::connect(
            (string) config('platform.service'),
            $environment,
            (string) config('platform.flags.host'),
            (int) config('platform.flags.port'),
            $this->flagCache(),
            (int) config('platform.flags.cache_seconds'),
        );
    }

    private function flagCache(): FlagCache
    {
        return self::isFpm() ? new ApcuFlagCache() : new InMemoryFlagCache();
    }

    private static function isFpm(): bool
    {
        return PHP_SAPI === 'fpm-fcgi';
    }
}
