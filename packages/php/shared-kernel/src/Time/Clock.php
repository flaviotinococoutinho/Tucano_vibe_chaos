<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Time;

use DateTimeImmutable;

interface Clock
{
    /** Current instant, always in UTC. */
    public function now(): DateTimeImmutable;
}
