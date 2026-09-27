<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity\Snowflake\Sequence;

/**
 * For long-running processes (CLI workers, Swoole, tests): the counter lives
 * in the process memory and resets every millisecond.
 */
final class InMemorySequence implements SequenceProvider
{
    private int $millis = -1;
    private int $sequence = -1;

    public function next(int $millis): int
    {
        if ($millis !== $this->millis) {
            $this->millis = $millis;
            $this->sequence = -1;
        }

        return ++$this->sequence;
    }
}
