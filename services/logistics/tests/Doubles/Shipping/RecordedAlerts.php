<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\Alert;
use Logistics\Shipping\Application\Port\Driven\ForRaisingAlerts;

final class RecordedAlerts implements ForRaisingAlerts
{
    /** @var list<Alert> */
    public private(set) array $raised = [];

    public function raise(Alert $alert): void
    {
        $this->raised[] = $alert;
    }
}
