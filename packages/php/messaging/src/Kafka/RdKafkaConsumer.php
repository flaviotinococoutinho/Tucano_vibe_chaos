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
 * never loss. Handlers must therefore be idempotent (see Inbox).
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
            $this->poll($handler);
        }

        $this->consumer->close();
        $this->logger->info('consumer stopped', ['group' => $this->group]);
    }

    private function poll(MessageHandler $handler): void
    {
        $message = $this->consumer->consume(1_000);
        if ($message->err === RD_KAFKA_RESP_ERR__TIMED_OUT || $message->err === RD_KAFKA_RESP_ERR__PARTITION_EOF) {
            return;
        }
        if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            throw new RuntimeException('Kafka consumer error: ' . $message->errstr());
        }

        $this->process($handler, $message);
    }

    private function process(MessageHandler $handler, RdKafkaMessage $raw): void
    {
        $message = ReceivedMessage::fromRdKafka($raw);
        $this->retry->run(
            static fn() => $handler->handle($message),
            fn(Throwable $failure) => $this->deadLetter($message, $failure),
        );
        $this->consumer->commit($raw);
    }

    private function deadLetter(ReceivedMessage $message, Throwable $failure): void
    {
        $this->logger->error('message sent to the dead letter topic', [
            'group' => $this->group,
            'topic' => $message->topic,
            'partition' => $message->partition,
            'offset' => $message->offset,
            'error' => $failure->getMessage(),
        ]);

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
