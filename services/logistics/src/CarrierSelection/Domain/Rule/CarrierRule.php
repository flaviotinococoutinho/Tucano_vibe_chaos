<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain\Rule;

use Logistics\CarrierSelection\Domain\Carrier;
use Logistics\CarrierSelection\Domain\Carriers;
use Logistics\CarrierSelection\Domain\Consignment;
use Logistics\CarrierSelection\Domain\NoCarrierFits;

/**
 * One link of the carrier chain (Chain of Responsibility). A rule picks a
 * carrier or passes the consignment to the next one; when the last link finds
 * nothing either, no carrier fits.
 */
abstract readonly class CarrierRule
{
    public function __construct(private ?CarrierRule $next = null) {}

    /** @throws NoCarrierFits */
    final public function choose(Consignment $consignment, Carriers $carriers): Carrier
    {
        return $this->pick($consignment, $carriers)
            ?? $this->next?->choose($consignment, $carriers)
            ?? throw NoCarrierFits::for($consignment);
    }

    abstract protected function pick(Consignment $consignment, Carriers $carriers): ?Carrier;
}
