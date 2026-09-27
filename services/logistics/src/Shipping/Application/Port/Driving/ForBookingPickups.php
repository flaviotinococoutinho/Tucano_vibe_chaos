<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\BookingOutcome;
use Logistics\Shipping\Domain\Error\PickupNotBooked;
use Logistics\Shipping\Domain\Error\PickupRefused;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

interface ForBookingPickups
{
    /**
     * Asks the carrier to collect a shipment that waits for pickup. Asking again is
     * safe: the shipment id is the key, so the carrier books it once.
     *
     * @throws PickupNotBooked the carrier did not answer
     * @throws PickupRefused the carrier refused the request itself
     */
    public function book(ShipmentId $shipment): BookingOutcome;
}
