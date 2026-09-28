<?php

declare(strict_types=1);

namespace Tracking\Delivery\Application\Port\Driven;

use Tracking\Delivery\Domain\DeliveryNews;
use Tracking\Delivery\Domain\TrackingCode;

interface ForKeepingDeliveryNews
{
    /** Replaces the last news of the code; only the last one is kept, never a history. */
    public function keep(DeliveryNews $news): void;

    public function last(TrackingCode $code): ?DeliveryNews;
}
