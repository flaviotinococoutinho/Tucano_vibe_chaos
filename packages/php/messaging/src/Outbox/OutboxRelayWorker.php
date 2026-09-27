<?php

declare(strict_types=1);

namespace Tucano\Messaging\Outbox;

use Closure;
use Psr\Log\LoggerInterface;
use Throwable;
use Tucano\Messaging\Worker\StopSignal;

/** The relay loop: publish while there is work, back off when idle or when Kafka is down. */
final readonly class OutboxRelayWorker
{
    /** @param Closure(): bool $paused lets a feature flag pause the relay without stopping the process */
    public function __construct(
        private OutboxRelay $relay,
        private LoggerInterface $logger,
        private Closure $paused,
        private int $idleMilliseconds = 200,
        private int $failureMilliseconds = 2_000,
    ) {}

    public function run(StopSignal $stop): void
    {
        $this->logger->info('outbox relay started');
        while (!$stop->requested()) {
            usleep($this->tick() * 1_000);
        }
        $this->logger->info('outbox relay stopped');
    }

    /** @return int milliseconds to wait before the next batch */
    private function tick(): int
    {
        if (($this->paused)()) {
            return $this->failureMilliseconds;
        }
        try {
            $published = $this->relay->relayBatch();
        } catch (Throwable $failure) {
            $this->logger->warning('outbox relay failed, will retry', ['error' => $failure->getMessage()]);

            return $this->failureMilliseconds;
        }
        if ($published > 0) {
            $this->logger->info('outbox batch published', ['count' => $published]);

            return 0;
        }

        return $this->idleMilliseconds;
    }
}
