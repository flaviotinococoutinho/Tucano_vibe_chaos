<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Application\JourneyResult;
use Logistics\Shipping\Application\StalledJourney;
use Logistics\Shipping\Application\StalledJourneys;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StalledJourneysTest extends TestCase
{
    /** @return iterable<string, array{?JourneyResult, string}> */
    public static function lastChecks(): iterable
    {
        yield 'never checked' => [null, 'never compared with the carrier yet'];
        yield 'unknown to the carrier' => [JourneyResult::UnknownToCarrier, 'the carrier does not know it'];
        yield 'up to date' => [JourneyResult::UpToDate, 'the carrier has no news either'];
        yield 'carrier unreachable' => [JourneyResult::CarrierUnreachable, 'the carrier does not answer'];
        yield 'stopped' => [JourneyResult::Stopped, 'the state machine refused a step of the carrier history'];
        yield 'caught up' => [JourneyResult::CaughtUp, 'it caught up with the carrier and stopped again'];
    }

    #[Test]
    #[DataProvider('lastChecks')]
    public function the_last_round_of_the_reconciliation_says_why_it_stopped(?JourneyResult $lastCheck, string $reason): void
    {
        self::assertSame($reason, self::journey('TX02PX83Y5M5G00', $lastCheck)->reason());
    }

    #[Test]
    public function one_stalled_shipment_is_said_in_the_singular(): void
    {
        $alert = StalledJourneys::of([self::journey('TX02PX83Y5M5G00')], 1, new DateTimeImmutable('2026-09-27T14:00:00Z'))->toAlert();

        self::assertSame('1 shipment stalled with the carrier, no step since 2026-09-27 14:00 UTC', $alert->subject);
    }

    #[Test]
    public function the_same_shipments_are_the_same_news_whatever_the_round(): void
    {
        $first = StalledJourneys::of([self::journey('TX02PX83Y5M5G00'), self::journey('TX02PX9D4HQ2R01')], 2, new DateTimeImmutable('2026-09-27T14:00:00Z'))->toAlert();
        $later = StalledJourneys::of([self::journey('TX02PX9D4HQ2R01', JourneyResult::UpToDate), self::journey('TX02PX83Y5M5G00')], 2, new DateTimeImmutable('2026-09-27T14:15:00Z'))->toAlert();
        $another = StalledJourneys::of([self::journey('TX02PX83Y5M5G00'), self::journey('TX02PX9D4HQ2R01'), self::journey('TX02PXB0N7T1C02')], 3, new DateTimeImmutable('2026-09-27T14:30:00Z'))->toAlert();

        self::assertSame($first->fingerprint, $later->fingerprint);
        self::assertNotSame($first->fingerprint, $another->fingerprint, 'One more stalled shipment is news.');
    }

    #[Test]
    public function the_total_is_never_less_than_what_is_shown(): void
    {
        self::assertSame(1, StalledJourneys::of([self::journey('TX02PX83Y5M5G00')], 0, new DateTimeImmutable('2026-09-27T14:00:00Z'))->total);
    }

    private static function journey(string $trackingCode, ?JourneyResult $lastCheck = null): StalledJourney
    {
        return StalledJourney::of($trackingCode, 'picked_up', 'correio-nacional', new DateTimeImmutable('2026-09-27T09:00:00Z'), $lastCheck, $lastCheck === null ? null : new DateTimeImmutable('2026-09-27T09:05:00Z'));
    }
}
