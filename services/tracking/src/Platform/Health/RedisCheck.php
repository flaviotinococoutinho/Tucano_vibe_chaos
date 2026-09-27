<?php

declare(strict_types=1);

namespace Tracking\Platform\Health;

use Redis;
use RuntimeException;

/**
 * Every probe opens a phpredis connection of its own. The runtime hooks make the
 * socket yield to other coroutines instead of blocking the worker, and since no
 * other coroutine ever touches it, the probe cannot hit the "socket already bound
 * to another coroutine" error of a shared client. A pool (Swoole\Database\RedisPool)
 * pays off for steady traffic; for a probe every few seconds, a new connection also
 * answers what readiness asks: can this worker reach Redis right now?
 */
final readonly class RedisCheck implements HealthCheck
{
    public function __construct(
        private string $host,
        private int $port,
        private float $timeoutSeconds = 1.0,
    ) {}

    public function name(): string
    {
        return 'redis';
    }

    public function check(): void
    {
        $redis = new Redis();
        try {
            if (!$redis->connect($this->host, $this->port, $this->timeoutSeconds, null, 0, $this->timeoutSeconds)) {
                throw new RuntimeException(sprintf('Could not connect to %s:%d.', $this->host, $this->port));
            }
            $redis->ping();
        } finally {
            $redis->close();
        }
    }
}
