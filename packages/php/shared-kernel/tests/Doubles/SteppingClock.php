<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Doubles;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Tucano\SharedKernel\Time\Clock;

/** Returns the given instants one by one, to script how time moves in a test. */
final class SteppingClock implements Clock
{
    /** @var list<DateTimeImmutable> */
    private array $instants;

    public function __construct(string ...$instants)
    {
        $this->instants = array_map(
            static fn(string $instant): DateTimeImmutable => new DateTimeImmutable($instant, new DateTimeZone('UTC')),
            array_values($instants),
        );
    }

    public function now(): DateTimeImmutable
    {
        $next = array_shift($this->instants);
        if ($next === null) {
            throw new RuntimeException('The test clock ran out of instants.');
        }

        return $next;
    }
}
