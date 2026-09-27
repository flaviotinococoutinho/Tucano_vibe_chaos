<?php

declare(strict_types=1);

namespace Tucano\Messaging\Tests\Kafka;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\Messaging\Kafka\DeliveryFailed;
use Tucano\Messaging\Kafka\Message;
use Tucano\Messaging\Kafka\RdKafkaProducer;
use Tucano\Messaging\Tests\Doubles\RecordingLogger;

/** Needs no broker: the diagnostics about a refused connection are the subject. */
final class LibrdkafkaLogTest extends TestCase
{
    #[Test]
    public function librdkafka_diagnostics_reach_the_logger_with_a_psr_level(): void
    {
        $logger = new RecordingLogger();
        // Port 1 on loopback refuses every connection.
        $producer = new RdKafkaProducer('127.0.0.1:1', 'log-test', ['message.timeout.ms' => '1500'], $logger);

        $producer->send(new Message('nowhere.v1', 'key', 'payload'));
        try {
            $producer->flush(3_000);
        } catch (DeliveryFailed) {
            // Expected: the broker never answered. What matters here is what got logged meanwhile.
        }

        $fromLibrdkafka = array_filter($logger->records, static fn(array $record): bool => ($record['context']['source'] ?? null) === 'librdkafka');
        self::assertNotEmpty($fromLibrdkafka, 'Nothing from librdkafka reached the logger.');
        foreach ($fromLibrdkafka as $record) {
            self::assertContains($record['level'], ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug']);
            self::assertIsString($record['context']['facility'] ?? null);
        }
    }
}
