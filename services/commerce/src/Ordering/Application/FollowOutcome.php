<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

enum FollowOutcome: string
{
    case Applied = 'applied';

    /** The same shipment event arrived again: the inbox already has it. */
    case Duplicate = 'duplicate';
}
