<?php

declare(strict_types=1);

namespace Tests\Doubles;

use RuntimeException;

/**
 * A local port that accepts connections and never answers: the failure a
 * Toxiproxy timeout toxic causes, without Toxiproxy. The kernel completes the
 * TCP handshake from the listen backlog, so the client connects and then waits.
 */
final readonly class Blackhole
{
    /** @param resource $server */
    private function __construct(private mixed $server) {}

    public static function open(): self
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($server === false) {
            throw new RuntimeException(sprintf('Could not open a blackhole port: %s (%d)', $errorMessage, $errorCode));
        }

        return new self($server);
    }

    public function port(): int
    {
        $address = (string) stream_socket_get_name($this->server, false);

        return (int) substr($address, (int) strrpos($address, ':') + 1);
    }

    public function __destruct()
    {
        fclose($this->server);
    }
}
