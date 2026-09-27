<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application\Port\Driven;

use Logistics\CarrierSelection\Domain\Carriers;

interface ForFindingCarriers
{
    public function all(): Carriers;
}
