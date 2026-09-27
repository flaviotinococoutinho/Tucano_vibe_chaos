<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

/** Producer for tests: keeps what was sent and can pretend Kafka is down. */
final class InMemoryProducer implements Producer
{
    /** @var list<Message> */
    private array $queued = [];

    /** @var list<Message> */
    private array $delivered = [];

    private ?string $outage = null;

    public function send(Message $message): void
    {
        $this->queued[] = $message;
    }

    public function flush(int $timeoutMs = 10_000): void
    {
        $queued = $this->queued;
        $this->queued = [];
        if ($this->outage !== null) {
            throw DeliveryFailed::because([$this->outage]);
        }

        $this->delivered = [...$this->delivered, ...$queued];
    }

    public function failWith(string $reason): void
    {
        $this->outage = $reason;
    }

    public function recover(): void
    {
        $this->outage = null;
    }

    /** @return list<Message> */
    public function delivered(): array
    {
        return $this->delivered;
    }
}
