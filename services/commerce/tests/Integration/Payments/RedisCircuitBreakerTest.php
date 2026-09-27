<?php

declare(strict_types=1);

namespace Tests\Integration\Payments;

use Commerce\Shared\Adapter\Driven\CircuitBreaker\CircuitState;
use Commerce\Shared\Adapter\Driven\CircuitBreaker\RedisCircuitBreaker;
use Illuminate\Redis\RedisManager;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

#[Group('integration')]
final class RedisCircuitBreakerTest extends TestCase
{
    private RedisCircuitBreaker $breaker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->breaker = $this->breaker($this->app->make(RedisManager::class));
    }

    #[Test]
    public function it_opens_at_the_threshold_and_refuses_calls_until_the_wait_is_over(): void
    {
        $this->breaker->recordFailure();
        $this->breaker->recordFailure();
        self::assertSame(CircuitState::Closed, $this->breaker->state());

        $this->breaker->recordFailure();

        self::assertSame(CircuitState::Open, $this->breaker->state());
        self::assertFalse($this->breaker->allowsCall());
        self::assertSame(1, $this->breaker->secondsUntilRetry());
    }

    #[Test]
    public function after_the_wait_a_single_trial_call_closes_it(): void
    {
        $this->trip();

        self::assertSame(CircuitState::HalfOpen, $this->breaker->state());
        self::assertTrue($this->breaker->allowsCall());
        self::assertFalse($this->breaker->allowsCall(), 'Only one trial call at a time.');

        $this->breaker->recordSuccess();

        self::assertSame(CircuitState::Closed, $this->breaker->state());
    }

    #[Test]
    public function a_failed_trial_opens_it_again(): void
    {
        $this->trip();
        $this->breaker->allowsCall();

        $this->breaker->recordFailure();

        self::assertSame(CircuitState::Open, $this->breaker->state());
    }

    #[Test]
    public function without_redis_the_calls_go_through(): void
    {
        config(['database.redis.default.host' => '127.0.0.1', 'database.redis.default.port' => 1]);
        $this->app->forgetInstance('redis');
        $breaker = $this->breaker($this->app->make(RedisManager::class));

        self::assertTrue($breaker->allowsCall());
        self::assertSame(0, $breaker->secondsUntilRetry());
    }

    /** Opens the circuit and waits out its one second. */
    private function trip(): void
    {
        foreach (range(1, 3) as $failure) {
            $this->breaker->recordFailure();
        }
        usleep(1_100_000);
    }

    private function breaker(RedisManager $redis): RedisCircuitBreaker
    {
        return new RedisCircuitBreaker($redis, new NullLogger(), 'test-' . bin2hex(random_bytes(4)), failureThreshold: 3, windowSeconds: 30, openSeconds: 1, trialSeconds: 5);
    }
}
