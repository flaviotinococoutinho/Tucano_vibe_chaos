<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\StalledJourney;
use Logistics\Shipping\Application\UseCase\WatchStalledJourneys;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Shipping\RecordedAlerts;
use Tests\Doubles\Shipping\ScriptedStalledJourneys;
use Tucano\SharedKernel\Time\FrozenClock;

final class WatchStalledJourneysTest extends TestCase
{
    private ScriptedStalledJourneys $journeys;

    private RecordedAlerts $alerts;

    protected function setUp(): void
    {
        $this->journeys = new ScriptedStalledJourneys();
        $this->alerts = new RecordedAlerts();
    }

    #[Test]
    public function the_shipments_stalled_for_too_long_go_out_in_one_alert_oldest_first(): void
    {
        $this->journeys->are(
            StalledJourney::of('TX02PX83Y5M5G00', 'ready_for_pickup', 'correio-nacional', new DateTimeImmutable('2026-09-27T09:10:00Z'), JourneyResult::UnknownToCarrier, new DateTimeImmutable('2026-09-27T09:12:00Z')),
            StalledJourney::of('TX02PX9D4HQ2R01', 'out_for_delivery', 'tucano-express', new DateTimeImmutable('2026-09-27T13:40:00Z')),
        );

        $stalled = $this->watch(limit: 50)->watch();

        self::assertSame(2, $stalled->total);
        self::assertEquals(new DateTimeImmutable('2026-09-27T14:00:00Z'), $this->journeys->askedBefore, 'An hour without a step, counted from now.');
        self::assertSame(50, $this->journeys->askedLimit);
        self::assertCount(1, $this->alerts->raised);
        self::assertSame('2 shipments stalled with the carrier, no step since 2026-09-27 14:00 UTC', $this->alerts->raised[0]->subject);
        self::assertSame([
            'TX02PX83Y5M5G00 ready_for_pickup with correio-nacional since 2026-09-27 09:10 UTC: the carrier does not know it, the same answer since 2026-09-27 09:12 UTC',
            'TX02PX9D4HQ2R01 out_for_delivery with tucano-express since 2026-09-27 13:40 UTC: never compared with the carrier yet',
        ], $this->alerts->raised[0]->lines);
    }

    #[Test]
    public function beyond_the_limit_the_alert_lists_the_oldest_and_counts_the_rest(): void
    {
        $this->journeys->are(
            StalledJourney::of('TX02PX83Y5M5G00', 'picked_up', 'correio-nacional', new DateTimeImmutable('2026-09-27T08:00:00Z')),
            StalledJourney::of('TX02PX83Y5M5G01', 'picked_up', 'correio-nacional', new DateTimeImmutable('2026-09-27T09:00:00Z')),
            StalledJourney::of('TX02PX83Y5M5G02', 'picked_up', 'correio-nacional', new DateTimeImmutable('2026-09-27T10:00:00Z')),
        );

        $this->watch(limit: 1)->watch();

        self::assertStringStartsWith('3 shipments stalled', $this->alerts->raised[0]->subject);
        self::assertSame('... and 2 more, the newest ones.', $this->alerts->raised[0]->lines[1]);
        self::assertCount(2, $this->alerts->raised[0]->lines);
    }

    #[Test]
    public function a_round_that_finds_nothing_raises_nothing(): void
    {
        $stalled = $this->watch(limit: 50)->watch();

        self::assertTrue($stalled->isEmpty());
        self::assertSame([], $this->alerts->raised);
    }

    private function watch(int $limit): WatchStalledJourneys
    {
        return new WatchStalledJourneys($this->journeys, $this->alerts, new FrozenClock('2026-09-27T15:00:00Z'), 3600, $limit);
    }
}
