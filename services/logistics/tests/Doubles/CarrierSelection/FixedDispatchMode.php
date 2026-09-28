<?php

declare(strict_types=1);

namespace Tests\Doubles\CarrierSelection;

use Logistics\CarrierSelection\Application\Port\Driven\ForChoosingDispatchMode;
use Logistics\CarrierSelection\Domain\DispatchMode;

/** The dispatch mode a test chooses, and can change halfway. */
final class FixedDispatchMode implements ForChoosingDispatchMode
{
    public function __construct(public DispatchMode $mode) {}

    public function current(): DispatchMode
    {
        return $this->mode;
    }
}
