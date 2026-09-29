<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter\Driven;

use Closure;
use Redis;
use RedisException;
use Tracking\Delivery\Adapter\DeliveryNewsJson;
use Tracking\Delivery\Application\Port\Driven\ForBroadcastingDeliveryNews;
use Tracking\Delivery\Application\Port\Driven\ForKeepingDeliveryNews;
use Tracking\Delivery\Domain\DeliveriesUnavailable;
use Tracking\Delivery\Domain\DeliveryNews;
use Tracking\Delivery\Domain\TrackingCode;

/**
 * The last news of each tracking code, under delivery:<code>:last with a time to live, and the
 * channel every worker of every instance listens to. A worker serves many requests at once and a
 * phpredis connection belongs to one coroutine at a time, so each call opens its own, the way the
 * readiness check does. At one report per courier per second that costs less than keeping a pool
 * honest about broken connections; a pool is the next step when the fleet grows.
 */
final readonly class RedisDeliveryNews implements ForKeepingDeliveryNews, ForBroadcastingDeliveryNews
{
    public const string CHANNEL = 'deliveries';

    public function __construct(
        private string $host,
        private int $port,
        private float $timeoutSeconds,
        private int $ttlSeconds,
    ) {}

    public function keep(DeliveryNews $news): void
    {
        $this->with(fn(Redis $redis): mixed => $redis->set(self::key($news->trackingCode()), DeliveryNewsJson::encode($news), ['EX' => $this->ttlSeconds]));
    }

    public function last(TrackingCode $code): ?DeliveryNews
    {
        $json = $this->with(static fn(Redis $redis): mixed => $redis->get(self::key($code)));

        return is_string($json) ? DeliveryNewsJson::decode($json) : null;
    }

    public function broadcast(DeliveryNews $news): void
    {
        $this->with(static fn(Redis $redis): mixed => $redis->publish(self::CHANNEL, DeliveryNewsJson::encode($news)));
    }

    /** @param Closure(Redis): mixed $work */
    private function with(Closure $work): mixed
    {
        $redis = new Redis();
        try {
            $redis->connect($this->host, $this->port, $this->timeoutSeconds);

            return $work($redis);
        } catch (RedisException $unreachable) {
            throw DeliveriesUnavailable::because($unreachable);
        } finally {
            $redis->close();
        }
    }

    private static function key(TrackingCode $code): string
    {
        return sprintf('delivery:%s:last', $code->value);
    }
}
