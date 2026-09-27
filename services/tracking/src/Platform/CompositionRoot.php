<?php

declare(strict_types=1);

namespace Tracking\Platform;

use DateTimeZone;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Log\LoggerInterface;
use Tracking\Platform\Health\HealthController;
use Tracking\Platform\Health\Readiness;
use Tracking\Platform\Health\RedisCheck;
use Tracking\Platform\Http\Kernel;
use Tracking\Platform\Http\Route;
use Tracking\Platform\Http\Router;
use Tracking\Platform\Logging\CorrelationIdProcessor;
use Tracking\Platform\Logging\JsonLineFormatter;
use Tucano\FeatureFlags\Cache\InMemoryFlagCache;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\Flagd;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\FeatureFlags\ProductionGuard;

/**
 * Wires the object graph of one worker. The server boots it in workerStart,
 * after the fork, so a socket opened by one worker process is never used by
 * another.
 */
final readonly class CompositionRoot
{
    private function __construct(
        public Kernel $kernel,
        public FeatureFlags $featureFlags,
    ) {}

    public static function boot(Config $config, LoggerInterface $logger): self
    {
        $health = new HealthController(new Readiness([new RedisCheck($config->redisHost, $config->redisPort)]), $logger);
        $router = new Router(
            new Route('GET', '/health/live', $health->live(...)),
            new Route('GET', '/health/ready', $health->ready(...)),
        );

        return new self(new Kernel($router, $logger), self::featureFlags($config));
    }

    /** One JSON object per line on stderr, with the service name and, inside a request, its correlation id. */
    public static function logger(Config $config): Logger
    {
        $handler = new StreamHandler('php://stderr', $config->logLevel);
        $handler->setFormatter(new JsonLineFormatter());

        return new Logger(
            $config->service,
            [$handler],
            [new PsrLogMessageProcessor(), new CorrelationIdProcessor()],
            new DateTimeZone('UTC'),
        );
    }

    private static function featureFlags(Config $config): FeatureFlags
    {
        if ($config->flagsDriver === FlagsDriver::Memory) {
            return new ProductionGuard(new InMemoryFlags(), $config->environment);
        }

        // The worker outlives thousands of requests, so its own memory is the cache; APCu is only needed under PHP-FPM.
        return Flagd::connect(
            $config->service,
            $config->environment,
            $config->flagdHost,
            $config->flagdPort,
            new InMemoryFlagCache(),
        );
    }
}
