<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Application\Port\Driven\ForFindingStalledJourneys;
use Logistics\Shipping\Application\StalledJourney;
use Logistics\Shipping\Application\StalledJourneys;

/** The analytical read, answered from a list the test writes, oldest first; it remembers what it was asked. */
final class ScriptedStalledJourneys implements ForFindingStalledJourneys
{
    /** @var list<StalledJourney> */
    private array $journeys = [];

    public private(set) ?DateTimeImmutable $askedBefore = null;

    public private(set) ?int $askedLimit = null;

    public function are(StalledJourney ...$journeys): self
    {
        $this->journeys = array_values($journeys);

        return $this;
    }

    public function quietSince(DateTimeImmutable $before, int $limit): StalledJourneys
    {
        $this->askedBefore = $before;
        $this->askedLimit = $limit;

        return StalledJourneys::of(array_slice($this->journeys, 0, $limit), count($this->journeys), $before);
    }
}
