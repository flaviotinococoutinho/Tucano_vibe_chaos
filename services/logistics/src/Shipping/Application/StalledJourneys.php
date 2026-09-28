<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use DateTimeImmutable;
use DateTimeZone;

/** What the watch found: the stalled journeys it shows, oldest first, out of how many there are. */
final readonly class StalledJourneys
{
    /** @param list<StalledJourney> $shown */
    private function __construct(public array $shown, public int $total, public DateTimeImmutable $quietSince) {}

    /** @param list<StalledJourney> $shown */
    public static function of(array $shown, int $total, DateTimeImmutable $quietSince): self
    {
        return new self($shown, max($total, count($shown)), $quietSince->setTimezone(new DateTimeZone('UTC')));
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function toAlert(): Alert
    {
        $lines = array_map(static fn(StalledJourney $journey): string => $journey->toLine(), $this->shown);
        $hidden = $this->total - count($this->shown);
        if ($hidden > 0) {
            $lines[] = sprintf('... and %d more, the newest ones.', $hidden);
        }

        return Alert::of(
            $this->fingerprint(),
            sprintf('%d %s stalled with the carrier, no step since %s', $this->total, $this->total === 1 ? 'shipment' : 'shipments', $this->quietSince->format('Y-m-d H:i T')),
            ...$lines,
        );
    }

    /** The same shipments, as many as before: the same news, whatever the time of the round. */
    private function fingerprint(): string
    {
        $codes = array_map(static fn(StalledJourney $journey): string => $journey->trackingCode, $this->shown);
        sort($codes);

        return 'stalled-journeys:' . hash('xxh128', sprintf('%d|%s', $this->total, implode(',', $codes)));
    }
}
