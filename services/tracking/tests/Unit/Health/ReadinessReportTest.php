<?php

declare(strict_types=1);

namespace Tests\Unit\Health;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tracking\Platform\Health\ReadinessReport;

final class ReadinessReportTest extends TestCase
{
    #[Test]
    public function it_is_up_when_every_check_is_up(): void
    {
        $report = (new ReadinessReport())->with('redis', 3, null);

        self::assertTrue($report->isHealthy());
        self::assertSame(['status' => 'up', 'checks' => ['redis' => ['status' => 'up', 'latencyMs' => 3]]], $report->toArray());
    }

    #[Test]
    public function one_check_down_takes_the_service_down(): void
    {
        $report = (new ReadinessReport())
            ->with('redis', 1000, 'Connection timed out')
            ->with('flagd', 2, null);

        self::assertFalse($report->isHealthy());
        self::assertSame([
            'status' => 'down',
            'checks' => [
                'redis' => ['status' => 'down', 'latencyMs' => 1000, 'error' => 'Connection timed out'],
                'flagd' => ['status' => 'up', 'latencyMs' => 2],
            ],
        ], $report->toArray());
    }

    #[Test]
    public function with_returns_a_new_report(): void
    {
        $empty = new ReadinessReport();
        $empty->with('redis', 1, 'Connection refused');

        self::assertTrue($empty->isHealthy());
    }
}
