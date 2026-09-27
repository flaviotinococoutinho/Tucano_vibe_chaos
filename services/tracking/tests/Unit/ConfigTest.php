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
        self::assertSame(2, $config->workers);
        self::assertSame(['toxiproxy', 16379], [$config->redisHost, $config->redisPort]);
        self::assertSame(FlagsDriver::Flagd, $config->flagsDriver);
        self::assertSame(['toxiproxy', 18013], [$config->flagdHost, $config->flagdPort]);
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
            'SWOOLE_WORKERS' => '4',
            'REDIS_HOST' => 'redis',
            'REDIS_PORT' => '6379',
            'FLAGS_DRIVER' => 'memory',
            'FLAGD_HOST' => 'flagd',
            'FLAGD_PORT' => '8013',
        ]);

        self::assertSame('tracking-canary', $config->service);
        self::assertSame(Environment::Local, $config->environment);
        self::assertSame(Level::Debug, $config->logLevel);
        self::assertSame(4, $config->workers);
        self::assertSame(['redis', 6379], [$config->redisHost, $config->redisPort]);
        self::assertSame(FlagsDriver::Memory, $config->flagsDriver);
        self::assertSame(['flagd', 8013], [$config->flagdHost, $config->flagdPort]);
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
        yield 'log level outside PSR-3' => ['LOG_LEVEL', 'verbose'];
        yield 'unknown flag driver' => ['FLAGS_DRIVER', 'launchdarkly'];
    }
}
