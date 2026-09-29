<?php

declare(strict_types=1);

namespace Tracking\Delivery\Application\UseCase;

use Tracking\Delivery\Application\Port\Driven\ForBroadcastingDeliveryNews;
use Tracking\Delivery\Application\Port\Driven\ForKeepingDeliveryNews;
use Tracking\Delivery\Application\Port\Driving\ForReportingDeliveries;
use Tracking\Delivery\Domain\DeliveryNews;
use Tucano\SharedKernel\Documentation\UseCase;

/**
 * UC-TRK-01: the courier's device reports where it is. The news is kept first, so a follower
 * who connects right after the broadcast still gets it, and then broadcast.
 */
#[UseCase('UC-TRK-01')]
final readonly class ReportDelivery implements ForReportingDeliveries
{
    public function __construct(private ForKeepingDeliveryNews $news, private ForBroadcastingDeliveryNews $followers) {}

    public function report(DeliveryNews $news): void
    {
        $this->news->keep($news);
        $this->followers->broadcast($news);
    }
}
