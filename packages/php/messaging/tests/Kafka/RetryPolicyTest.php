<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\RetryPolicy;

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
        }, static fn(Throwable $failure) => self::fail('should not give up'));

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
        );

        self::assertCount(9, $this->sleeps);
        foreach ($this->sleeps as $sleep) {
            self::assertLessThanOrEqual(400, $sleep);
        }
    }

    private function policy(int $maxAttempts, int $baseDelayMs = 50, int $maxDelayMs = 1_000): RetryPolicy
    {
        return new RetryPolicy($maxAttempts, $baseDelayMs, $maxDelayMs, function (int $milliseconds): void {
            $this->sleeps[] = $milliseconds;
        });
    }
}
