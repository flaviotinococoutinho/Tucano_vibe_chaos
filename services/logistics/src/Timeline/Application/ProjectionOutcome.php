<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application;

enum ProjectionOutcome: string
{
    case Applied = 'applied';
    /** The read model already had the step: a redelivery or a replay. */
    case Duplicate = 'duplicate';
}
