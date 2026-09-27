<?php

declare(strict_types=1);

namespace Commerce\Payments\Application\Port\Driving;

use Commerce\Payments\Application\ProviderOutcome;
use Commerce\Payments\Application\SettleResult;

interface ForSettlingPayments
{
    public function settle(ProviderOutcome $outcome): SettleResult;
}
