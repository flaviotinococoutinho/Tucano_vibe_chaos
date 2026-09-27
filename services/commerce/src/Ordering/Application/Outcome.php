<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

/** Whether this request created the order or repeated one that already did. */
enum Outcome
{
    case Placed;
    case Replayed;
}
