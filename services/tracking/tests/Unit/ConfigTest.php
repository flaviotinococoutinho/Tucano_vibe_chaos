<?php

declare(strict_types=1);

namespace Tests\Unit;

use InvalidArgumentException;
use Monolog\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tracking\Platform\Config;
use Tracking\Platform\FlagsDriver;
use Tucano\FeatureFlags\Environment;

final class ConfigTest extends TestCase
{
    #[Test]
    public function the_defaults_fit_the_compose_stack(): void
    {
        $config = Config::fromEnvironment([]);

        self::assertSame('tracking', $config->service);
        self::assertSame(Level::Info, $config->logLevel);
        self::assertSame(['0.0.0.0', 9501, 2, 5], [$config->host, $config->port, $config->workers, $config->maxWaitSeconds]);
        self::assertSame(['toxiproxy', 16379, 1000], [$config->redisHost, $config->redisPort, $config->redisTimeoutMs]);
        self::assertSame(FlagsDriver::Flagd, $config->flagsDriver);
        self::assertSame(['toxiproxy', 18013], [$config->flagdHost, $config->flagdPort]);
        self::assertSame([2, 300, 200], [$config->flagsCacheSeconds, $config->flagdTimeoutMs, $config->flagdConnectTimeoutMs]);
        self::assertSame(['whsec_local_couriers', 900], [$config->couriersSecret, $config->liveNewsTtlSeconds]);
    }

    #[Test]
    public function the_live_delivery_takes_its_secret_and_its_memory_from_the_environment(): void
    {
        $config = Config::fromEnvironment(['COURIERS_SECRET' => 'whsec_rotated', 'LIVE_NEWS_TTL_SECONDS' => '300']);

        self::assertSame(['whsec_rotated', 300], [$config->couriersSecret, $config->liveNewsTtlSeconds]);
        $this->expectException(InvalidArgumentException::class);

        Config::fromEnvironment(['LIVE_NEWS_TTL_SECONDS' => '0']);
    }

    #[Test]
    public function a_missing_environment_counts_as_production(): void
    {
        self::assertSame(Environment::Production, Config::fromEnvironment([])->environment);
    }

    #[Test]
    public function every_value_comes_from_the_environment(): void
    {
        $config = Config::fromEnvironment([
            'APP_NAME' => 'tracking-canary',
            'APP_ENV' => 'local',
            'LOG_LEVEL' => 'DEBUG',
            'HOST' => '127.0.0.1',
            'PORT' => '9601',
            'SWOOLE_WORKERS' => '4',
            'SWOOLE_MAX_WAIT_SECONDS' => '8',
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'REDIS_TIMEOUT_MS' => '250',
            'FLAGS_DRIVER' => 'memory',
            'FLAGD_HOST' => 'flagd',
            'FLAGD_PORT' => '8013',
            'FLAGS_CACHE_SECONDS' => '5',
            'FLAGD_TIMEOUT_MS' => '500',
            'FLAGD_CONNECT_TIMEOUT_MS' => '100',
        ]);

        self::assertSame('tracking-canary', $config->service);
        self::assertSame(Environment::Local, $config->environment);
        self::assertSame(Level::Debug, $config->logLevel);
        self::assertSame(['127.0.0.1', 9601, 4, 8], [$config->host, $config->port, $config->workers, $config->maxWaitSeconds]);
        self::assertSame(['redis', 6379, 250], [$config->redisHost, $config->redisPort, $config->redisTimeoutMs]);
        self::assertSame(FlagsDriver::Memory, $config->flagsDriver);
        self::assertSame(['flagd', 8013], [$config->flagdHost, $config->flagdPort]);
        self::assertSame([5, 500, 100], [$config->flagsCacheSeconds, $config->flagdTimeoutMs, $config->flagdConnectTimeoutMs]);
    }

    #[Test]
    public function blank_values_fall_back_to_the_defaults(): void
    {
        $config = Config::fromEnvironment(['APP_NAME' => '', 'SWOOLE_WORKERS' => ' ', 'REDIS_HOST' => '']);

        self::assertSame(['tracking', 2, 'toxiproxy'], [$config->service, $config->workers, $config->redisHost]);
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function the_server_refuses_to_boot_with_an_invalid_value(string $variable, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('%s must be', $variable));

        Config::fromEnvironment([$variable => $value]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidValues(): iterable
    {
        yield 'no workers' => ['SWOOLE_WORKERS', '0'];
        yield 'workers in words' => ['SWOOLE_WORKERS', 'two'];
        yield 'port out of range' => ['REDIS_PORT', '70000'];
        yield 'port with a fraction' => ['FLAGD_PORT', '8013.5'];
        yield 'a server port out of range' => ['PORT', '0'];
        yield 'a timeout in words' => ['REDIS_TIMEOUT_MS', 'one second'];
        yield 'no time to finish on SIGTERM' => ['SWOOLE_MAX_WAIT_SECONDS', '0'];
        yield 'log level outside PSR-3' => ['LOG_LEVEL', 'verbose'];
        yield 'unknown flag driver' => ['FLAGS_DRIVER', 'launchdarkly'];
    }
}
