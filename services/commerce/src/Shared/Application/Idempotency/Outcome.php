<?php

declare(strict_types=1);

namespace Commerce\Shared\Application\Idempotency;

/** Whether this request did the work or repeated one that already did. */
enum Outcome
{
    case Fresh;
    case Replayed;
}
