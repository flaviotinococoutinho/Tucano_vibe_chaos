<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity\Snowflake;

use Tucano\SharedKernel\Identity\Snowflake\Sequence\SequenceProvider;
use Tucano\SharedKernel\Time\Clock;

final class SnowflakeGenerator
{
    private int $lastMillis = 0;

    public function __construct(
        private readonly NodeId $node,
        private readonly SequenceProvider $sequence,
        private readonly Clock $clock,
    ) {}

    public function next(): Snowflake
    {
        $millis = $this->currentMillis();
        if ($millis < $this->lastMillis) {
            throw ClockMovedBackwards::between($this->lastMillis, $millis);
        }

        $sequence = $this->sequence->next($millis);
        while ($sequence > Snowflake::MAX_SEQUENCE) {
            $millis = $this->millisAfter($millis);
            $sequence = $this->sequence->next($millis);
        }

        $this->lastMillis = $millis;

        return Snowflake::compose($millis, $this->node, $sequence);
    }

    /** 4096 ids in the same millisecond: wait for the next one. */
    private function millisAfter(int $millis): int
    {
        do {
            $current = $this->currentMillis();
        } while ($current <= $millis);

        return $current;
    }

    private function currentMillis(): int
    {
        return (int) $this->clock->now()->format('Uv');
    }
}
