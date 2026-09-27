<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use PDOException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\RetryOutcome;
use Tucano\Messaging\Kafka\RetryPolicy;
use Tucano\Messaging\Worker\StopSignal;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase
{
    /** @var list<int> */
    private array $sleeps = [];

    #[Test]
    public function a_transient_failure_is_retried_until_it_works(): void
    {
        $calls = 0;
        $policy = $this->policy(maxAttempts: 5);

        $policy->run(function () use (&$calls): void {
            if (++$calls < 3) {
                throw new RuntimeException('broker not available');
            }
        }, static fn(Throwable $failure) => self::fail('should not give up'), new StopSignal());

        self::assertSame(3, $calls);
        self::assertCount(2, $this->sleeps);
    }

    #[Test]
    public function it_gives_up_once_the_attempts_run_out(): void
    {
        $givenUp = [];
        $this->policy(maxAttempts: 3)->run(
            static fn() => throw new RuntimeException('still down'),
            static function (Throwable $failure) use (&$givenUp): void {
                $givenUp[] = $failure->getMessage();
            },
            new StopSignal(),
        );

        self::assertSame(['still down'], $givenUp);
        self::assertCount(2, $this->sleeps);
    }

    #[Test]
    public function a_permanent_failure_skips_the_retries(): void
    {
        $givenUp = 0;
        $this->policy(maxAttempts: 5)->run(
            static fn() => throw new PermanentFailure('payload is not a CloudEvent'),
            static function () use (&$givenUp): void {
                $givenUp++;
            },
            new StopSignal(),
        );

        self::assertSame(1, $givenUp);
        self::assertSame([], $this->sleeps);
    }

    #[Test]
    public function the_wait_never_goes_beyond_the_cap(): void
    {
        $this->policy(maxAttempts: 10, baseDelayMs: 100, maxDelayMs: 400)->run(
            static fn() => throw new RuntimeException('down'),
            static fn() => null,
            new StopSignal(),
        );

        self::assertCount(9, $this->sleeps);
        foreach ($this->sleeps as $sleep) {
            self::assertLessThanOrEqual(400, $sleep);
        }
    }

    #[Test]
    public function a_lost_connection_keeps_retrying_past_the_attempt_limit(): void
    {
        $calls = 0;
        $outcome = $this->policy(maxAttempts: 2)->run(function () use (&$calls): void {
            if (++$calls < 7) {
                throw new PDOException('SQLSTATE[08006] [7] server closed the connection unexpectedly');
            }
        }, static fn(Throwable $failure) => self::fail('a lost connection should not give up'), new StopSignal());

        self::assertSame([RetryOutcome::Succeeded, 7], [$outcome, $calls]);
        self::assertCount(6, $this->sleeps);
    }

    #[Test]
    public function a_stop_during_the_wait_leaves_the_message_for_after_the_restart(): void
    {
        $stop = new StopSignal();
        $waits = 0;
        $policy = new RetryPolicy(5, 50, 1_000, static function () use ($stop, &$waits): void {
            if (++$waits === 2) {
                $stop->stop();
            }
        });

        $outcome = $policy->run(
            static fn() => throw new RuntimeException('database is restarting'),
            static fn(Throwable $failure) => self::fail('a stop is not a reason to give up'),
            $stop,
        );

        self::assertSame([RetryOutcome::Interrupted, 2], [$outcome, $waits]);
    }

    #[Test]
    public function every_retry_is_announced_with_its_failure_and_its_wait(): void
    {
        $announced = [];
        $this->policy(maxAttempts: 3)->run(
            static fn() => throw new RuntimeException('down'),
            static fn() => null,
            new StopSignal(),
            static function (Throwable $failure, int $wait) use (&$announced): void {
                $announced[] = [$failure->getMessage(), $wait];
            },
        );

        self::assertSame(['down', 'down'], array_column($announced, 0));
        self::assertSame($this->sleeps, array_column($announced, 1));
    }

    private function policy(int $maxAttempts, int $baseDelayMs = 50, int $maxDelayMs = 1_000): RetryPolicy
    {
        return new RetryPolicy($maxAttempts, $baseDelayMs, $maxDelayMs, function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
        });
    }
}
