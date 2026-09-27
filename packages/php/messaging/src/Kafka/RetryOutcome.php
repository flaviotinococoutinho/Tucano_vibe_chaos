<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

enum RetryOutcome
{
    case Succeeded;

    /** The failure was permanent, or the attempts ran out: the message went to the give-up path. */
    case GaveUp;

    /** A stop came while retrying: the message was neither handled nor given up, and comes back after the restart. */
    case Interrupted;
}
