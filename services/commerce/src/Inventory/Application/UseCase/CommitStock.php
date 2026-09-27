<?php

declare(strict_types=1);

namespace Commerce\Inventory\Application\UseCase;

use Commerce\Inventory\Application\Port\Driven\ForRecordingReservations;
use Commerce\Inventory\Application\Port\Driving\ForCommittingStock;
use Tucano\SharedKernel\Documentation\UseCase;

#[UseCase('UC-INV-04')]
final readonly class CommitStock implements ForCommittingStock
{
    public function __construct(private ForRecordingReservations $reservations) {}

    public function commit(string $orderId): void
    {
        $this->reservations->commit($orderId);
    }
}
