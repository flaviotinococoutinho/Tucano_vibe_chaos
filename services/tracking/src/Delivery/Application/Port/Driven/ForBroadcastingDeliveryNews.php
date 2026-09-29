<?php

declare(strict_types=1);

namespace Tracking\Delivery\Application\Port\Driven;

use Tracking\Delivery\Domain\DeliveryNews;

interface ForBroadcastingDeliveryNews
{
    /** Sends the news to the followers of its code, wherever they are connected. */
    public function broadcast(DeliveryNews $news): void;
}
