<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driving;

use Logistics\Shipping\Application\ReconciledJourney;

interface ForReconcilingJourneys
{
    /** Puts the shipment quiet for the longest side by side with its carrier; null when none has been quiet for long enough. */
    public function reconcileNext(): ?ReconciledJourney;
}
