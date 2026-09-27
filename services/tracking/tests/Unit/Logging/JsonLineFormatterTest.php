<?php

declare(strict_types=1);

namespace Tests\Unit\Logging;

use DateTimeImmutable;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tracking\Platform\Logging\JsonLineFormatter;

final class JsonLineFormatterTest extends TestCase
{
    #[Test]
    public function it_writes_one_flat_json_object_per_line(): void
    {
        $line = (new JsonLineFormatter())->format(self::record('Worker started', ['worker' => 1], ['correlation_id' => 'req-1#1']));

        self::assertSame(1, substr_count($line, "\n"));
        self::assertStringEndsWith("\n", $line);
        self::assertSame([
            'timestamp' => '2026-09-27T12:00:00.123Z',
            'level' => 'info',
            'message' => 'Worker started',
            'service' => 'tracking',
            'correlation_id' => 'req-1#1',
            'worker' => 1,
        ], self::decode($line));
    }

    #[Test]
    public function the_context_cannot_replace_the_standard_fields(): void
    {
        $line = (new JsonLineFormatter())->format(self::record('Position received', ['message' => 'spoofed', 'service' => 'other', 'level' => 'emergency']));

        $fields = self::decode($line);
        self::assertSame(['Position received', 'tracking', 'info'], [$fields['message'], $fields['service'], $fields['level']]);
    }

    #[Test]
    public function exceptions_keep_class_message_and_trace(): void
    {
        $line = (new JsonLineFormatter())->format(self::record('Request failed', ['exception' => new RuntimeException('Connection refused')]));

        $exception = self::decode($line)['exception'];
        self::assertIsArray($exception);
        self::assertSame(RuntimeException::class, $exception['class']);
        self::assertSame('Connection refused', $exception['message']);
        self::assertArrayHasKey('trace', $exception);
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $extra
     */
    private static function record(string $message, array $context = [], array $extra = []): LogRecord
    {
        return new LogRecord(new DateTimeImmutable('2026-09-27T12:00:00.123456Z'), 'tracking', Level::Info, $message, $context, $extra);
    }

    /** @return array<mixed> */
    private static function decode(string $line): array
    {
        $fields = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($fields);

        return $fields;
    }
}
