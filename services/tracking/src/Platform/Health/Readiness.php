<?php

declare(strict_types=1);

namespace Tracking\Platform\Health;

use Throwable;

final readonly class Readiness
{
    /** @param iterable<HealthCheck> $checks */
    public function __construct(private iterable $checks) {}

    public function probe(): ReadinessReport
    {
        $report = new ReadinessReport();
        foreach ($this->checks as $check) {
            $report = $report->with($check->name(), ...self::run($check));
        }

        return $report;
    }

    /** @return array{0: int, 1: ?string} latency in ms and the error, if any */
    private static function run(HealthCheck $check): array
    {
        $startedAt = hrtime(true);
        try {
            $check->check();

            return [self::elapsedMillis($startedAt), null];
        } catch (Throwable $error) {
            return [self::elapsedMillis($startedAt), $error->getMessage()];
        }
    }

    private static function elapsedMillis(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
