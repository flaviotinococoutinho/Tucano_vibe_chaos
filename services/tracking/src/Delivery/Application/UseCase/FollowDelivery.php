<?php

declare(strict_types=1);

namespace Tracking\Delivery\Application\UseCase;

use Tracking\Delivery\Application\Port\Driven\ForKeepingDeliveryNews;
use Tracking\Delivery\Application\Port\Driving\ForFollowingDeliveries;
use Tracking\Delivery\Domain\DeliveryNews;
use Tracking\Delivery\Domain\TrackingCode;
use Tucano\SharedKernel\Documentation\UseCase;

/** UC-TRK-03: a customer follows a parcel live, starting from the last known news, then every new one. */
#[UseCase('UC-TRK-03')]
final readonly class FollowDelivery implements ForFollowingDeliveries
{
    public function __construct(private ForKeepingDeliveryNews $news) {}

    public function lastNews(TrackingCode $code): ?DeliveryNews
    {
        return $this->news->last($code);
    }
}
