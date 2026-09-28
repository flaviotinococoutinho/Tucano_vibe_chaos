<?php

declare(strict_types=1);

namespace Tracking\Delivery\Domain;

/** What the courier's device reports, and what the followers of a tracking code receive (contracts/tracking). */
interface DeliveryNews
{
    public function trackingCode(): TrackingCode;

    /** @return array<string, int|float|string> the news as the live delivery contract writes it */
    public function toArray(): array;
}
