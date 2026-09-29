<?php

declare(strict_types=1);

namespace Tracking\Delivery\Application\Port\Driving;

use Tracking\Delivery\Domain\DeliveryNews;

interface ForReportingDeliveries
{
    /** Keeps the news as the last one of its tracking code and sends it to everyone following that code. */
    public function report(DeliveryNews $news): void;
}
