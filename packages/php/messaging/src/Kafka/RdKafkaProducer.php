<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use RdKafka\Conf;
use RdKafka\Message as RdKafkaMessage;
use RdKafka\Producer as RdKafkaClient;
use RdKafka\ProducerTopic;

final class RdKafkaProducer implements Producer
{
    private readonly RdKafkaClient $client;

    /** @var array<string, ProducerTopic> */
    private array $topics = [];

    /** @var list<string> */
    private array $failures = [];

    /** @param array<string, string> $settings extra librdkafka settings */
    public function __construct(string $brokers, string $clientId, array $settings = [])
    {
        $conf = new Conf();
        $conf->set('bootstrap.servers', $brokers);
        $conf->set('client.id', $clientId);
        // Idempotent producer: retries never duplicate or reorder a partition. It implies acks=all.
        $conf->set('enable.idempotence', 'true');
        $conf->set('acks', 'all');
        $conf->set('compression.type', 'lz4');
        $conf->set('linger.ms', '5');
        // Give up after 10 s so the caller (the outbox relay) can roll back and try again later.
        $conf->set('message.timeout.ms', '10000');
        foreach ($settings as $name => $value) {
            $conf->set($name, $value);
        }
        $conf->setDrMsgCb(function (RdKafkaClient $client, RdKafkaMessage $message): void {
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->failures[] = $message->errstr();
            }
        });

        $this->client = new RdKafkaClient($conf);
    }

    public function send(Message $message): void
    {
        $this->topic($message->topic)->producev(RD_KAFKA_PARTITION_UA, 0, $message->payload, $message->key, $message->headers);
        $this->client->poll(0);
    }

    public function flush(int $timeoutMs = 10_000): void
    {
        $result = $this->client->flush($timeoutMs);
        $failures = $this->failures;
        $this->failures = [];

        if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
            $failures[] = rd_kafka_err2str($result);
        }
        if ($failures !== []) {
            throw DeliveryFailed::because($failures);
        }
    }

    private function topic(string $name): ProducerTopic
    {
        return $this->topics[$name] ??= $this->client->newTopic($name);
    }
}
