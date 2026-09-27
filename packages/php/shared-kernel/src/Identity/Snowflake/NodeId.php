<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity\Snowflake;

use InvalidArgumentException;

/** Datacenter and worker that generated a Snowflake: 5 bits each. */
final readonly class NodeId
{
    public const int MAX = 31;

    public function __construct(public int $datacenter, public int $worker)
    {
        self::assertInRange('datacenter', $datacenter);
        self::assertInRange('worker', $worker);
    }

    private static function assertInRange(string $part, int $value): void
    {
        if ($value < 0 || $value > self::MAX) {
            throw new InvalidArgumentException(sprintf('The %s id must be between 0 and %d, got %d.', $part, self::MAX, $value));
        }
    }
}
