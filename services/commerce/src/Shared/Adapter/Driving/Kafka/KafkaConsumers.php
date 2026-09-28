<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driving\Kafka;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Psr\Log\LoggerInterface;
use Throwable;
use Tucano\Messaging\Kafka\Producer;
use Tucano\Messaging\Kafka\RdKafkaConsumer;
use Tucano\Messaging\Kafka\RetryPolicy;

/**
 * The consumers of the service, built with the settings of config/messaging.php:
 * the brokers, the poll and the retry. Each worker says its group, its topics
 * and, when it has them, the failures that wait without a limit.
 */
final readonly class KafkaConsumers
{
    public function __construct(private Repository $config, private Producer $deadLetters, private LoggerInterface $logger) {}

    /**
     * @param list<string>                    $topics
     * @param (Closure(Throwable): bool)|null $unlimited the failures that wait without counting; a lost database connection when null
     * @param string                          $retry     where the retry settings are in the config
     */
    public function subscribe(string $group, array $topics, ?Closure $unlimited = null, string $retry = 'messaging.consumer.retry'): RdKafkaConsumer
    {
        return new RdKafkaConsumer(
            (string) $this->config->get('messaging.brokers'),
            $group,
            $topics,
            $this->deadLetters,
            $this->logger,
            $this->retryPolicy($retry, $unlimited),
            (int) $this->config->get('messaging.consumer.poll_timeout_ms'),
        );
    }

    /** @param (Closure(Throwable): bool)|null $unlimited */
    public function retryPolicy(string $retry, ?Closure $unlimited = null): RetryPolicy
    {
        return new RetryPolicy(
            maxAttempts: (int) $this->config->get($retry . '.max_attempts'),
            baseDelayMs: (int) $this->config->get($retry . '.base_delay_ms'),
            maxDelayMs: (int) $this->config->get($retry . '.max_delay_ms'),
            unlimited: $unlimited,
        );
    }
}
