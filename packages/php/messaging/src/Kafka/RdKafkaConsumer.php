<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use Psr\Log\LoggerInterface;
use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RdKafka\Message as RdKafkaMessage;
use RuntimeException;
use Throwable;
use Tucano\Messaging\Worker\StopSignal;

/**
 * At-least-once consumer: the offset is committed only after the handler
 * finished (or the message went to the DLQ), so a crash means redelivery,
 * never loss. Handlers must therefore be idempotent (see Inbox). A stop in the
 * middle of the retries commits nothing, and the message comes back after the
 * restart; see RetryPolicy for which failures wait and which give up.
 */
final class RdKafkaConsumer
{
    private readonly KafkaConsumer $consumer;

    /** @param list<string> $topics */
    public function __construct(
        string $brokers,
        private readonly string $group,
        private readonly array $topics,
        private readonly Producer $deadLetters,
        private readonly LoggerInterface $logger,
        private readonly RetryPolicy $retry = new RetryPolicy(),
        // How long one poll waits for a record; it is also how long a SIGTERM may wait to be noticed.
        private readonly int $pollTimeoutMs = 1_000,
    ) {
        $conf = new Conf();
        $conf->set('bootstrap.servers', $brokers);
        $conf->set('group.id', $group);
        $conf->set('enable.auto.commit', 'false');
        $conf->set('auto.offset.reset', 'earliest');
        LibrdkafkaLog::route($conf, $logger);
        $this->consumer = new KafkaConsumer($conf);
    }

    public function run(MessageHandler $handler, StopSignal $stop): void
    {
        $this->consumer->subscribe($this->topics);
        $this->logger->info('consumer started', ['group' => $this->group, 'topics' => $this->topics]);

        while (!$stop->requested()) {
            $this->poll($handler, $stop);
        }

        $this->consumer->close();
        $this->logger->info('consumer stopped', ['group' => $this->group]);
    }

    private function poll(MessageHandler $handler, StopSignal $stop): void
    {
        $message = $this->consumer->consume($this->pollTimeoutMs);
        if ($message->err === RD_KAFKA_RESP_ERR__TIMED_OUT || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            return;
        }
        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException('Kafka consumer error: ' . $message->errstr());
        }

        $this->process($handler, $message, $stop);
    }

    private function process(MessageHandler $handler, RdKafkaMessage $raw, StopSignal $stop): void
    {
        $message = ReceivedMessage::fromRdKafka($raw);
        $outcome = $this->retry->run(
            static fn() => $handler->handle($message),
            fn(Throwable $failure) => $this->deadLetter($message, $failure),
            $stop,
            fn(Throwable $failure, int $wait) => $this->logger->warning('message failed, retrying in {wait} ms', [
                ...$this->positionOf($message),
                'wait' => $wait,
                'error' => $failure->getMessage(),
            ]),
        );
        if ($outcome === RetryOutcome::Interrupted) {
            $this->logger->info('stopped while retrying; the message comes back after the restart', $this->positionOf($message));

            return;
        }
        $this->consumer->commit($raw);
    }

    /** @return array{group: string, topic: string, partition: int, offset: int} */
    private function positionOf(ReceivedMessage $message): array
    {
        return ['group' => $this->group, 'topic' => $message->topic, 'partition' => $message->partition, 'offset' => $message->offset];
    }

    private function deadLetter(ReceivedMessage $message, Throwable $failure): void
    {
        $this->logger->error('message sent to the dead letter topic', [...$this->positionOf($message), 'error' => $failure->getMessage()]);

        $this->deadLetters->send(new Message('dlq.' . $this->group, $message->key, $message->payload, [
            ...$message->headers,
            'dlq-original-topic' => $message->topic,
            'dlq-original-partition' => (string) $message->partition,
            'dlq-original-offset' => (string) $message->offset,
            'dlq-error' => mb_substr($failure->getMessage(), 0, 500),
        ]));
        $this->deadLetters->flush();
    }
}
