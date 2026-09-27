<?php

declare(strict_types=1);

namespace Logistics\Shipping\Domain\Shipment;

/** Why a shipment stopped before pickup; the state machine knows one cause today. */
enum CancellationReason: string
{
    case OrderCancelled = 'order_cancelled';
}
