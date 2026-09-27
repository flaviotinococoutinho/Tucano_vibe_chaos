<?php

declare(strict_types=1);

namespace App\Providers;

use App\Health\DatabaseCheck;
use App\Health\HealthCheck;
use App\Health\Readiness;
use App\Health\RedisCheck;
use App\Logging\LogContext;
use Illuminate\Support\ServiceProvider;
use Tucano\FeatureFlags\Cache\ApcuFlagCache;
use Tucano\FeatureFlags\Cache\FlagCache;
use Tucano\FeatureFlags\Cache\InMemoryFlagCache;
use Tucano\FeatureFlags\Environment;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\Flagd;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\FeatureFlags\ProductionGuard;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\SystemClock;

/**
 * Wires the platform pieces every feature relies on: clock, feature flags,
 * log context and health checks. The flag cache depends on the runtime: PHP-FPM
 * shares APCu between its children, while a CLI process keeps it in memory.
 */
final class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // mysqlnd reads this from php.ini, not from the PDO options.
        ini_set('mysqlnd.net_read_timeout', (string) config('database.connections.mysql.read_timeout'));

        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(FeatureFlags::class, fn(): FeatureFlags => $this->featureFlags());
        $this->app->singleton(LogContext::class);

        $this->app->tag([DatabaseCheck::class, RedisCheck::class], HealthCheck::TAG);
        $this->app->when(Readiness::class)->needs('$checks')->giveTagged(HealthCheck::TAG);
    }

    public function boot(LogContext $logContext): void
    {
        $logContext->add('service', (string) config('platform.service'));
    }

    private function featureFlags(): FeatureFlags
    {
        $environment = Environment::fromName((string) config('platform.environment'));
        if (config('platform.flags.driver') === 'memory') {
            // Resolved from the container so a test can hand in the flag values it needs.
            return new ProductionGuard($this->app->make(InMemoryFlags::class), $environment);
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
        return PHP_SAPI === 'fpm-fcgi' ? new ApcuFlagCache() : new InMemoryFlagCache();
    }
}
