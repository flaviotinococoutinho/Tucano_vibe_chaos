<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Time;

use DateTimeImmutable;
use DateTimeZone;

/** Test clock: time only moves when the test says so. */
final class FrozenClock implements Clock
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable|string $now = '2026-09-27T12:00:00Z')
    {
        $this->now = self::utc($now);
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function moveTo(DateTimeImmutable|string $instant): void
    {
        $this->now = self::utc($instant);
    }

    public function advance(string $interval): void
    {
        $this->now = $this->now->modify($interval);
    }

    private static function utc(DateTimeImmutable|string $instant): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        $dateTime = $instant instanceof DateTimeImmutable ? $instant : new DateTimeImmutable($instant, $utc);

        // An explicit offset in the string wins over the constructor time zone, so convert anyway.
        return $dateTime->setTimezone($utc);
    }
}
