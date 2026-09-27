<?php

declare(strict_types=1);

namespace Tracking\Platform;

use InvalidArgumentException;
use Monolog\Level;
use Tucano\FeatureFlags\Environment;

/**
 * Everything the service reads from environment variables. The defaults fit the
 * compose stack, where Redis and flagd are reached through Toxiproxy. A bad value
 * stops the server at boot instead of failing requests later.
 */
final readonly class Config
{
    public function __construct(
        public string $service,
        public Environment $environment,
        public Level $logLevel,
        public int $workers,
        public string $redisHost,
        public int $redisPort,
        public FlagsDriver $flagsDriver,
        public string $flagdHost,
        public int $flagdPort,
    ) {}

    /** @param array<string, string> $env usually getenv() */
    public static function fromEnvironment(array $env): self
    {
        return new self(
            service: self::text($env, 'APP_NAME', 'tracking'),
            environment: Environment::fromName($env['APP_ENV'] ?? null),
            logLevel: self::logLevel(self::text($env, 'LOG_LEVEL', 'info')),
            workers: self::positiveInteger($env, 'SWOOLE_WORKERS', 2),
            redisHost: self::text($env, 'REDIS_HOST', 'toxiproxy'),
            redisPort: self::port($env, 'REDIS_PORT', 16379),
            flagsDriver: self::flagsDriver(self::text($env, 'FLAGS_DRIVER', 'flagd')),
            flagdHost: self::text($env, 'FLAGD_HOST', 'toxiproxy'),
            flagdPort: self::port($env, 'FLAGD_PORT', 18013),
        );
    }

    /** @param array<string, string> $env */
    private static function text(array $env, string $name, string $default): string
    {
        $value = trim($env[$name] ?? '');

        return $value === '' ? $default : $value;
    }

    /** @param array<string, string> $env */
    private static function positiveInteger(array $env, string $name, int $default): int
    {
        $value = self::text($env, $name, (string) $default);
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $integer !== false
            ? $integer
            : throw new InvalidArgumentException(sprintf('%s must be a positive integer, got "%s".', $name, $value));
    }

    /** @param array<string, string> $env */
    private static function port(array $env, string $name, int $default): int
    {
        $value = self::text($env, $name, (string) $default);
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        return $port !== false
            ? $port
            : throw new InvalidArgumentException(sprintf('%s must be a port between 1 and 65535, got "%s".', $name, $value));
    }

    private static function logLevel(string $name): Level
    {
        foreach (Level::cases() as $level) {
            if ($level->toPsrLogLevel() === strtolower($name)) {
                return $level;
            }
        }

        throw new InvalidArgumentException(sprintf('LOG_LEVEL must be a PSR-3 level such as debug, info or error, got "%s".', $name));
    }

    private static function flagsDriver(string $name): FlagsDriver
    {
        return FlagsDriver::tryFrom(strtolower($name))
            ?? throw new InvalidArgumentException(sprintf('FLAGS_DRIVER must be "flagd" or "memory", got "%s".', $name));
    }
}
