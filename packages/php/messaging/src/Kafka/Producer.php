<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

interface Producer
{
    /** Queues the message; nothing is guaranteed until flush() returns. */
    public function send(Message $message): void;

    /** Waits until every queued message is acknowledged, or throws DeliveryFailed. */
    public function flush(int $timeoutMs = 10_000): void;
}
