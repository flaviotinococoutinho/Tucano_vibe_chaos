<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity\Snowflake;

use RuntimeException;

/** Generating ids while the clock goes back could repeat ids, so the generator refuses. */
final class ClockMovedBackwards extends RuntimeException
{
    public static function between(int $lastMillis, int $currentMillis): self
    {
        return new self(sprintf('Clock moved backwards by %d ms; refusing to generate ids.', $lastMillis - $currentMillis));
    }
}
