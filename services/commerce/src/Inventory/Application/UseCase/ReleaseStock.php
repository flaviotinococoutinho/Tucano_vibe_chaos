<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\UseCase;

use Commerce\Inventory\Application\Port\Driven\ForRecordingReservations;
use Commerce\Inventory\Application\Port\Driving\ForReleasingStock;
use Tucano\SharedKernel\Documentation\UseCase;

#[UseCase('UC-INV-03')]
final readonly class ReleaseStock implements ForReleasingStock
{
    public function __construct(private ForRecordingReservations $reservations) {}

    public function release(string $orderId): void
    {
        $this->reservations->release($orderId);
    }
}
