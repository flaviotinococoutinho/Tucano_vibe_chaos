<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driven\CircuitBreaker;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\RedisManager;
use Psr\Log\LoggerInterface;
use RedisException;

/**
 * A circuit breaker whose state lives in Redis. PHP-FPM keeps nothing between
 * requests, so the count of failures has to live outside the process; in Redis,
 * every child of every instance sees the same circuit.
 *
 * Keys, all under circuit:<name>: `failures` counts inside a fixed window,
 * `open` exists while calls are refused (its TTL is the wait), `tripped` marks a
 * circuit that opened and has not been proved healthy yet, and `trial` is the
 * lock of the single call allowed while half open.
 *
 * If Redis itself fails, the breaker lets calls through: a broken breaker must
 * not take the whole service down with it. The connection is opened inside each
 * guarded operation for the same reason: phpredis connects on the spot.
 */
final readonly class RedisCircuitBreaker
{
    public function __construct(
        private RedisManager $redis,
        private LoggerInterface $logger,
        private string $name,
        private int $failureThreshold,
        private int $windowSeconds,
        private int $openSeconds,
        private int $trialSeconds,
    ) {}

    public function state(): CircuitState
    {
        return $this->guarded(function (): CircuitState {
            if ($this->exists('open')) {
                return CircuitState::Open;
            }

            return $this->exists('tripped') ? CircuitState::HalfOpen : CircuitState::Closed;
        }, CircuitState::Closed);
    }

    /** How long a caller should wait before trying; 0 when a call may go now. */
    public function secondsUntilRetry(): int
    {
        return $this->guarded(fn(): int => match ($this->state()) {
            CircuitState::Closed => 0,
            CircuitState::Open => max(1, (int) $this->connection()->command('ttl', [$this->key('open')])),
            // Somebody is already making the trial call.
            CircuitState::HalfOpen => $this->exists('trial') ? 1 : 0,
        }, 0);
    }

    /** Whether this caller may call now. While half open, only the one that takes the trial lock may. */
    public function allowsCall(): bool
    {
        return $this->guarded(fn(): bool => match ($this->state()) {
            CircuitState::Closed => true,
            CircuitState::Open => false,
            CircuitState::HalfOpen => (bool) $this->connection()->command('set', [$this->key('trial'), '1', ['nx', 'ex' => $this->trialSeconds]]),
        }, true);
    }

    public function recordSuccess(): void
    {
        $this->guarded(function (): void {
            if ($this->exists('tripped')) {
                $this->logger->info('Circuit {circuit} closed', ['circuit' => $this->name]);
            }
            $this->connection()->command('del', [$this->key('failures'), $this->key('tripped'), $this->key('trial')]);
        }, null);
    }

    public function recordFailure(): void
    {
        $this->guarded(function (): void {
            if ($this->state() === CircuitState::HalfOpen) {
                $this->open('the trial call failed');

                return;
            }
            $failures = (int) $this->connection()->command('incr', [$this->key('failures')]);
            if ($failures === 1) {
                $this->connection()->command('expire', [$this->key('failures'), $this->windowSeconds]);
            }
            if ($failures >= $this->failureThreshold) {
                $this->open(sprintf('%d failures in %d s', $failures, $this->windowSeconds));
            }
        }, null);
    }

    private function open(string $reason): void
    {
        $this->connection()->command('setex', [$this->key('open'), $this->openSeconds, '1']);
        $this->connection()->command('set', [$this->key('tripped'), '1']);
        $this->connection()->command('del', [$this->key('failures'), $this->key('trial')]);
        $this->logger->warning('Circuit {circuit} opened for {seconds} s: {reason}', [
            'circuit' => $this->name,
            'seconds' => $this->openSeconds,
            'reason' => $reason,
        ]);
    }

    private function exists(string $key): bool
    {
        return (int) $this->connection()->command('exists', [$this->key($key)]) > 0;
    }

    private function connection(): Connection
    {
        return $this->redis->connection();
    }

    private function key(string $name): string
    {
        return sprintf('circuit:%s:%s', $this->name, $name);
    }

    /**
     * @template T
     *
     * @param callable(): T $operation
     * @param T $whenRedisFails
     *
     * @return T
     */
    private function guarded(callable $operation, mixed $whenRedisFails): mixed
    {
        try {
            return $operation();
        } catch (RedisException $failure) {
            $this->logger->warning('Circuit {circuit} cannot reach Redis, letting calls through: {message}', [
                'circuit' => $this->name,
                'message' => $failure->getMessage(),
            ]);

            return $whenRedisFails;
        }
    }
}
