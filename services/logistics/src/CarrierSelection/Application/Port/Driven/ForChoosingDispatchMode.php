<?php

declare(strict_types=1);

namespace Logistics\CarrierSelection\Application\Port\Driven;

use Logistics\CarrierSelection\Domain\DispatchMode;

interface ForChoosingDispatchMode
{
    /** The mode of the moment; when the answer cannot be had, partners only, which always works. */
    public function current(): DispatchMode;
}
