<?php

declare(strict_types=1);

namespace Tucano\Messaging\Kafka;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RdKafka\Conf;

/**
 * librdkafka writes its own diagnostics to stderr as plain text, which breaks
 * the one-JSON-line-per-event rule of the logs. Routed here, they go through the
 * service logger like any other line. php-rdkafka sets log.queue for this, so
 * the callback runs on the PHP thread during poll() or consume(), never on one
 * of the librdkafka threads.
 */
final class LibrdkafkaLog
{
    /** librdkafka reports syslog severities, 0 (emergency) to 7 (debug). */
    private const array LEVELS = [
        0 => LogLevel::EMERGENCY,
        1 => LogLevel::ALERT,
        2 => LogLevel::CRITICAL,
        3 => LogLevel::ERROR,
        4 => LogLevel::WARNING,
        5 => LogLevel::NOTICE,
        6 => LogLevel::INFO,
        7 => LogLevel::DEBUG,
    ];

    private function __construct() {}

    public static function route(Conf $conf, LoggerInterface $logger): void
    {
        $conf->setLogCb(static function (mixed $kafka, int $level, string $facility, string $message) use ($logger): void {
            $logger->log(self::LEVELS[$level] ?? LogLevel::DEBUG, $message, ['source' => 'librdkafka', 'facility' => $facility]);
        });
    }
}
