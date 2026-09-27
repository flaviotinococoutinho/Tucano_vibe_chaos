<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Support\InCoroutine;
use Tracking\Platform\Logging\CorrelationIdProcessor;
use Tracking\Platform\RequestContext;

final class CorrelationIdProcessorTest extends TestCase
{
    #[Test]
    public function lines_written_during_a_request_carry_its_correlation_id(): void
    {
        $record = InCoroutine::run(static function (): LogRecord {
            RequestContext::bindCorrelationId('req-1#1');

            return (new CorrelationIdProcessor())(self::record());
        });

        self::assertSame(['correlation_id' => 'req-1#1'], $record->extra);
    }

    #[Test]
    public function lines_written_outside_a_request_have_none(): void
    {
        self::assertSame([], (new CorrelationIdProcessor())(self::record())->extra);
    }

    private static function record(): LogRecord
    {
        return new LogRecord(new DateTimeImmutable(), 'tracking', Level::Info, 'Position received');
    }
}
