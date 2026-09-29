<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\UseCase;

use Commerce\Ordering\Application\OrderSummary;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrderViews;
use Commerce\Ordering\Application\Port\Driving\ForProjectingOrderViews;
use Commerce\Ordering\Application\ProjectionOutcome;
use Commerce\Ordering\Application\StatusMove;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * UC-ORD-08: every event of commerce.orders.v2 keeps the view of its order in the
 * customer's list. order.placed opens the view and each later event moves it on, by
 * version: a redelivery, a replay or an event late never takes a view back, and a
 * consumer group reading the topic from the start rebuilds the whole list.
 */
#[UseCase('UC-ORD-08')]
final readonly class ProjectOrderView implements ForProjectingOrderViews
{
    public function __construct(private ForStoringOrderViews $views) {}

    public function open(OrderSummary $order): ProjectionOutcome
    {
        return $this->views->open($order) ? ProjectionOutcome::Applied : ProjectionOutcome::Duplicate;
    }

    public function move(StatusMove $move): ProjectionOutcome
    {
        return $this->views->move($move);
    }
}
