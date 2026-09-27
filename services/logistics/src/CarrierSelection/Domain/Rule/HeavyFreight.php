<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Domain\Rule;

use Logistics\CarrierSelection\Domain\Carrier;
use Logistics\CarrierSelection\Domain\Carriers;
use Logistics\CarrierSelection\Domain\Consignment;

/** The last resort: a freight company for what no regular carrier takes. */
final readonly class HeavyFreight extends CarrierRule
{
    public const string CARRIER = 'carga-pesada';

    protected function pick(Consignment $consignment, Carriers $carriers): ?Carrier
    {
        return $carriers->only(self::CARRIER)->thatCarry($consignment)->smallest();
    }
}
