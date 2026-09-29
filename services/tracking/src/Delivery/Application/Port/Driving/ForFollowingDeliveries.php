<?php

declare(strict_types=1);

namespace Tracking\Delivery\Application\Port\Driving;

use Tracking\Delivery\Domain\DeliveryNews;
use Tracking\Delivery\Domain\TrackingCode;

interface ForFollowingDeliveries
{
    /** What a new follower sees first: the last news of the code, if there is one still kept. */
    public function lastNews(TrackingCode $code): ?DeliveryNews;
}
