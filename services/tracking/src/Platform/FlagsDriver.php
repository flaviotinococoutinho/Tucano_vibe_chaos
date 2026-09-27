<?php

declare(strict_types=1);

namespace Tracking\Platform;

enum FlagsDriver: string
{
    /** flagd over HTTP, as in the compose stack. */
    case Flagd = 'flagd';

    /** Flags held in memory, for tests and for running without flagd. */
    case Memory = 'memory';
}
