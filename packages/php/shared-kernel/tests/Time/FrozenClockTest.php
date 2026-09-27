<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Time;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Time\FrozenClock;
use Tucano\SharedKernel\Time\SystemClock;

#[CoversClass(FrozenClock::class)]
#[CoversClass(SystemClock::class)]
final class FrozenClockTest extends TestCase
{
    #[Test]
    public function time_only_moves_when_the_test_moves_it(): void
    {
        $clock = new FrozenClock('2026-09-27T09:00:00-03:00');

        self::assertSame('2026-09-27T12:00:00+00:00', $clock->now()->format(DATE_ATOM));

        $clock->advance('+15 minutes');

        self::assertSame('2026-09-27T12:15:00+00:00', $clock->now()->format(DATE_ATOM));
    }

    #[Test]
    public function the_system_clock_is_always_utc(): void
    {
        self::assertSame('UTC', (new SystemClock())->now()->getTimezone()->getName());
    }
}
