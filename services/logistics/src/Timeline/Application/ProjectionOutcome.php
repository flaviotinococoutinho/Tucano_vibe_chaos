<?php

declare(strict_types=1);

namespace Logistics\Timeline\Application;

enum ProjectionOutcome: string
{
    case Applied = 'applied';

    /** Both read models already had the step: a redelivery or a replay. */
    case Duplicate = 'duplicate';
}
