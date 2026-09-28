<?php

declare(strict_types=1);

namespace Tests\Doubles\Ordering;

use Commerce\Ordering\Application\FollowOutcome;
use Commerce\Ordering\Application\Port\Driving\ForFollowingShipments;
use Commerce\Ordering\Application\ShipmentNews;
use Tucano\SharedKernel\Domain\DomainError;

final class RecordedShipmentFollowing implements ForFollowingShipments
{
    /** @var list<array{string, ShipmentNews}> the step and the news, in order */
    public private(set) array $calls = [];

    private ?DomainError $refusal = null;

    public function refuseWith(DomainError $refusal): void
    {
        $this->refusal = $refusal;
    }

    public function recordShipped(ShipmentNews $news): FollowOutcome
    {
        return $this->record('shipped', $news);
    }

    public function recordDelivered(ShipmentNews $news): FollowOutcome
    {
        return $this->record('delivered', $news);
    }

    public function recordReturned(ShipmentNews $news): FollowOutcome
    {
        return $this->record('returned', $news);
    }

    private function record(string $step, ShipmentNews $news): FollowOutcome
    {
        if ($this->refusal !== null) {
            throw $this->refusal;
        }
        $this->calls[] = [$step, $news];

        return FollowOutcome::Applied;
    }
}
