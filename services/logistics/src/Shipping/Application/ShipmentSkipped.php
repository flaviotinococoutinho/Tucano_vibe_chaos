<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

/** Why a paid order got no shipment this time. */
enum ShipmentSkipped
{
    /** The same event was handled before. */
    case Repeated;

    /** The cancellation of the order was handled first, and a cancelled order does not ship. */
    case OrderCancelled;
}
