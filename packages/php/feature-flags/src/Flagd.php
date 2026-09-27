<?php

declare(strict_types=1);

namespace Tucano\FeatureFlags;

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Psr7\HttpFactory;
use OpenFeature\OpenFeatureAPI;
use OpenFeature\Providers\Flagd\config\HttpConfig;
use OpenFeature\Providers\Flagd\FlagdProvider;
use Tucano\FeatureFlags\Cache\FlagCache;

/**
 * Wires the whole chain for a service:
 * ProductionGuard -> CachedFlags -> OpenFeatureFlags -> flagd over HTTP.
 */
final readonly class Flagd
{
    private function __construct() {}

    public static function connect(
        string $service,
        Environment $environment,
        string $host,
        int $port,
        FlagCache $cache,
        int $cacheSeconds = 2,
    ): FeatureFlags {
        // Flags must never slow a request down: short timeouts, then the fallback wins.
        $http = new HttpClient(['timeout' => 0.3, 'connect_timeout' => 0.2]);
        $factory = new HttpFactory();

        $api = OpenFeatureAPI::getInstance();
        $api->setProvider(new FlagdProvider([
            'protocol' => 'http',
            'host' => $host,
            'port' => $port,
            'httpConfig' => new HttpConfig($http, $factory, $factory),
        ]));

        $flags = new OpenFeatureFlags($api->getClient($service, '1'));

        return new ProductionGuard(new CachedFlags($flags, $cache, $cacheSeconds), $environment);
    }
}
