<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Domain\Error\LabelNotStored;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\ShippingLabel;

interface ForStoringLabels
{
    /**
     * Stores the label under a key made of the tracking code: storing it again overwrites
     * the same object, so a retried job leaves no copies behind.
     *
     * @throws LabelNotStored
     */
    public function store(TrackingCode $trackingCode, LabelDocument $label): ShippingLabel;
}
