<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application;

enum ProjectionOutcome: string
{
    case Applied = 'applied';

    /** The view already had this change, or a newer one: a redelivery, a replay or an event that came late. */
    case Duplicate = 'duplicate';

    /** No view to move: the order.placed of the order never reached the projection (it left the topic with the retention, or went to the dead letters). */
    case Missing = 'missing';
}
