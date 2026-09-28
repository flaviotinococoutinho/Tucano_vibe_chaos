<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application\Port\Driven;

use Logistics\Shipping\Application\Alert;

interface ForRaisingAlerts
{
    public function raise(Alert $alert): void;
}
