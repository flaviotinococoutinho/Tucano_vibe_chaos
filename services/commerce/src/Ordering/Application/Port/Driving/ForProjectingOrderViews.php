<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\OrderSummary;
use Commerce\Ordering\Application\ProjectionOutcome;
use Commerce\Ordering\Application\StatusMove;

interface ForProjectingOrderViews
{
    /** A placed order opens its view in the customer's list; a view already there changes nothing. */
    public function open(OrderSummary $order): ProjectionOutcome;

    /** The order moved on; a move the view already has, or an older one, changes nothing. */
    public function move(StatusMove $move): ProjectionOutcome;
}
