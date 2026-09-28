<?php

declare(strict_types=1);

namespace Commerce\Ordering\Application\Port\Driving;

use Commerce\Ordering\Application\FollowOutcome;
use Commerce\Ordering\Application\ShipmentNews;

/** The order follows its shipment, UC-ORD-04: each step Logistics publishes that matters to the customer. */
interface ForFollowingShipments
{
    /** The carrier picked the parcels up. */
    public function recordShipped(ShipmentNews $news): FollowOutcome;

    /** The parcels reached the customer. */
    public function recordDelivered(ShipmentNews $news): FollowOutcome;

    /** The parcels came back to the fulfillment center: the order is returned and its payment goes back. */
    public function recordReturned(ShipmentNews $news): FollowOutcome;
}
