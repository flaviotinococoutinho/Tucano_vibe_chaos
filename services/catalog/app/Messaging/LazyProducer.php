<?php

declare(strict_types=1);

namespace App\Messaging;

use Closure;
use Tucano\Messaging\Kafka\Message;
use Tucano\Messaging\Kafka\Producer;

/**
 * Creates the real producer on the first message. librdkafka starts its threads and
 * connects to the brokers as soon as a producer exists, and most requests are reads
 * that never publish anything.
 */
final class LazyProducer implements Producer
{
    private ?Producer $producer = null;

    /** @param Closure(): Producer $create */
    public function __construct(private readonly Closure $create) {}

    public function send(Message $message): void
    {
        $this->producer ??= ($this->create)();
        $this->producer->send($message);
    }

    /** Nothing sent means nothing to wait for, and no producer to create. */
    public function flush(int $timeoutMs = 10_000): void
    {
        $this->producer?->flush($timeoutMs);
    }
}
