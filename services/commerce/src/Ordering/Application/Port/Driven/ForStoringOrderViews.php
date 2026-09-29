<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driven;

use Commerce\Ordering\Application\OrderSummary;
use Commerce\Ordering\Application\ProjectionOutcome;
use Commerce\Ordering\Application\StatusMove;

/** The view of each order in its customer's list, kept by version so it never goes back in time. */
interface ForStoringOrderViews
{
    /** @return bool false when the order already had its view */
    public function open(OrderSummary $order): bool;

    /** Applies the move only to a view at an older version. */
    public function move(StatusMove $move): ProjectionOutcome;
}
