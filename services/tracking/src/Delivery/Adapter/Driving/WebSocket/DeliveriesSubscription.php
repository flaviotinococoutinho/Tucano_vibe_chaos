<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter\Driving\WebSocket;

use Psr\Log\LoggerInterface;
use Redis;
use RedisException;
use Swoole\Coroutine;
use Tracking\Delivery\Adapter\Driven\RedisDeliveryNews;

/**
 * Every worker listens to the deliveries channel for the life of the process, in a coroutine
 * of its own: with the hooks on, phpredis waits for a message without blocking the requests.
 * A lost connection subscribes again; stopping cancels the wait (see bin/server.php).
 */
final class DeliveriesSubscription
{
    private bool $stopping = false;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly float $timeoutSeconds,
        private readonly LoggerInterface $logger,
    ) {}

    public function run(LiveFollowers $followers): void
    {
        while (!$this->stopping) {
            try {
                $redis = new Redis();
                $redis->connect($this->host, $this->port, $this->timeoutSeconds);
                // A subscription waits for as long as it takes; the default read timeout would cut it.
                $redis->setOption(Redis::OPT_READ_TIMEOUT, -1);
                $redis->subscribe([RedisDeliveryNews::CHANNEL], static function (Redis $redis, string $channel, string $news) use ($followers): void {
                    $followers->deliver($news);
                });
            } catch (RedisException $lost) {
                // stop() flips the flag from another callback while this coroutine waits, and then
                // cancels the wait, which lands here: a cancelled wait is not a lost channel.
                if ($this->stopped()) {
                    return;
                }
                $this->logger->warning('Lost the deliveries channel, subscribing again in 1 s: {error}', ['error' => $lost->getMessage()]);
                Coroutine::sleep(1);
            }
        }
    }

    private function stopped(): bool
    {
        return $this->stopping;
    }

    public function stop(): void
    {
        $this->stopping = true;
    }
}
