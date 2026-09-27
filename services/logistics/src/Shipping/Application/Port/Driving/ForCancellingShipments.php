<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\CancellationOutcome;
use Logistics\Shipping\Application\CancelledOrder;
use Logistics\Shipping\Domain\Error\TransitionNotAllowed;

interface ForCancellingShipments
{
    /** @throws TransitionNotAllowed when the shipment already left the warehouse */
    public function cancel(CancelledOrder $order): CancellationOutcome;
}
