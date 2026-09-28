<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Illuminate\Contracts\Cache\Repository;
use Logistics\Shipping\Application\Alert;
use Logistics\Shipping\Application\Port\Driven\ForRaisingAlerts;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The repeat window of an alert manager, as a decorator: an alert goes out
 * the first time its fingerprint shows up, and then only after the window.
 * Cache::add is a SET NX EX on Redis, so two watches at once send one alert.
 * The window starts when the alert goes out: one that failed is forgotten,
 * and the next round tries again. Without the cache the alert goes out
 * anyway, because the same alert twice is better than an alert lost.
 */
final readonly class DeduplicatedAlerts implements ForRaisingAlerts
{
    public function __construct(
        private ForRaisingAlerts $alerts,
        private Repository $cache,
        private LoggerInterface $logger,
        private int $repeatAfterSeconds,
    ) {}

    public function raise(Alert $alert): void
    {
        $key = 'alerts:' . $alert->fingerprint;
        if (!$this->opensWindow($key, $alert)) {
            return;
        }
        try {
            $this->alerts->raise($alert);
        } catch (Throwable $failure) {
            $this->logger->error('The alert did not go out, the next round tries again: {message}', ['message' => $failure->getMessage()]);
            $this->forget($key);
        }
    }

    private function opensWindow(string $key, Alert $alert): bool
    {
        try {
            return $this->cache->add($key, $alert->subject, $this->repeatAfterSeconds);
        } catch (Throwable $failure) {
            $this->logger->warning('Alert sent without deduplication: {message}', ['message' => $failure->getMessage()]);

            return true;
        }
    }

    private function forget(string $key): void
    {
        try {
            $this->cache->forget($key);
        } catch (Throwable $failure) {
            $this->logger->warning('The alert waits for its window, the cache did not forget it: {message}', ['message' => $failure->getMessage()]);
        }
    }
}
