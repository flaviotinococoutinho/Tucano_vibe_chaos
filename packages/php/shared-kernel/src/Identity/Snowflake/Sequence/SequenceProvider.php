<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity\Snowflake\Sequence;

interface SequenceProvider
{
    /** Next sequence number (starting at 0) inside the given millisecond. */
    public function next(int $millis): int;
}
