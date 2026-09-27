<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driven\CircuitBreaker;

enum CircuitState
{
    /** Calls go through; failures are counted. */
    case Closed;

    /** Too many failures: no call goes out until the wait is over. */
    case Open;

    /** The wait is over: one trial call decides between Closed and Open. */
    case HalfOpen;
}
