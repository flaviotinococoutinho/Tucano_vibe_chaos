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
        public string $host,
        public int $port,
        public int $workers,
        public int $maxWaitSeconds,
        public string $redisHost,
        public int $redisPort,
        public int $redisTimeoutMs,
        public FlagsDriver $flagsDriver,
        public string $flagdHost,
        public int $flagdPort,
        public int $flagsCacheSeconds,
        public int $flagdTimeoutMs,
        public int $flagdConnectTimeoutMs,
        public string $couriersSecret,
        public int $liveNewsTtlSeconds,
    ) {}

    /** @param array<string, string> $env usually getenv() */
    public static function fromEnvironment(array $env): self
    {
        return new self(
            service: self::text($env, 'APP_NAME', 'tracking'),
            environment: Environment::fromName($env['APP_ENV'] ?? null),
            logLevel: self::logLevel(self::text($env, 'LOG_LEVEL', 'info')),
            host: self::text($env, 'HOST', '0.0.0.0'),
            port: self::port($env, 'PORT', 9501),
            workers: self::positiveInteger($env, 'SWOOLE_WORKERS', 2),
            // On SIGTERM, requests in flight get this long to finish, within Docker's 10 second stop timeout.
            maxWaitSeconds: self::positiveInteger($env, 'SWOOLE_MAX_WAIT_SECONDS', 5),
            redisHost: self::text($env, 'REDIS_HOST', 'toxiproxy'),
            redisPort: self::port($env, 'REDIS_PORT', 16379),
            redisTimeoutMs: self::positiveInteger($env, 'REDIS_TIMEOUT_MS', 1000),
            flagsDriver: self::flagsDriver(self::text($env, 'FLAGS_DRIVER', 'flagd')),
            flagdHost: self::text($env, 'FLAGD_HOST', 'toxiproxy'),
            flagdPort: self::port($env, 'FLAGD_PORT', 18013),
            flagsCacheSeconds: self::positiveInteger($env, 'FLAGS_CACHE_SECONDS', 2),
            flagdTimeoutMs: self::positiveInteger($env, 'FLAGD_TIMEOUT_MS', 300),
            flagdConnectTimeoutMs: self::positiveInteger($env, 'FLAGD_CONNECT_TIMEOUT_MS', 200),
            // The courier devices sign their reports with it, the way the carriers sign their webhooks.
            couriersSecret: self::text($env, 'COURIERS_SECRET', 'whsec_local_couriers'),
            // How long the last news of a tracking code is kept for a follower who arrives late.
            liveNewsTtlSeconds: self::positiveInteger($env, 'LIVE_NEWS_TTL_SECONDS', 900),
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
