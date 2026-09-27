<?php

declare(strict_types=1);

namespace Tucano\Messaging\Worker;

enum WorkerState
{
    case Running;
    case Stopping;
}
