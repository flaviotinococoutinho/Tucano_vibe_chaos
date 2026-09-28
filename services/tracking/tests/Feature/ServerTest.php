<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine\Http\Client;
use Swoole\WebSocket\CloseFrame;
use Swoole\WebSocket\Frame;
use Tests\Support\InCoroutine;

/**
 * Starts bin/server.php the way the container does and talks to it over TCP,
 * so the Swoole side (callbacks, BASE mode, hooks, shutdown) runs for real.
 */
final class ServerTest extends TestCase
{
    private const string HOST = '127.0.0.1';
    private const int PORT = 9501;
    /** The default of COURIERS_SECRET, the secret the simulated devices sign with. */
    private const string COURIERS_SECRET = 'whsec_local_couriers';
    private const int SIGKILL = 9;
    private const int SIGTERM = 15;

    /** @var resource|null */
    private $server = null;
    private string $logFile = '';

    protected function setUp(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'tracking-server-');
        $output = ['file', $this->logFile, 'a'];
        $server = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/bin/server.php'],
            [0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output],
            $pipes,
            null,
            ['SWOOLE_WORKERS' => '1', 'LOG_LEVEL' => 'debug'] + getenv(),
        );
        self::assertIsResource($server);
        $this->server = $server;
        $this->waitUntilListening();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server, self::SIGKILL);
            proc_close($this->server);
        }
        @unlink($this->logFile);
    }

    #[Test]
    public function it_serves_http_and_echoes_the_correlation_id(): void
    {
        [$status, $headers, $body] = self::get('/health/live', 'server-test#1');

        self::assertSame(200, $status);
        self::assertSame('server-test#1', $headers['x-correlation-id']);
        self::assertSame('{"status":"up"}', $body);
    }

    #[Test]
    public function unknown_paths_get_a_problem(): void
    {
        [$status, $headers] = self::get('/v1/nothing-here');

        self::assertSame(404, $status);
        self::assertSame('application/problem+json', $headers['content-type']);
    }

    #[Test]
    public function websocket_upgrades_to_any_other_path_are_refused(): void
    {
        $response = self::exchange([
            'GET /ws/shipments/TX02PQRFBTW5G03 HTTP/1.1',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version: 13',
            'X-Correlation-Id: ws-test#1',
        ]);

        self::assertStringStartsWith('HTTP/1.1 404', $response);
        self::assertStringContainsString('"correlationId":"ws-test#1"', $response);
    }

    #[Test]
    public function a_report_without_the_couriers_signature_is_refused(): void
    {
        $response = self::exchange(['POST /v1/positions HTTP/1.1', 'Content-Type: application/json', 'Content-Length: 2', 'Courier-Signature: t=1,v1=' . str_repeat('0', 64)], '{}');

        self::assertStringStartsWith('HTTP/1.1 401', $response);
        self::assertStringContainsString('The Courier-Signature is stale.', $response);
    }

    #[Test]
    #[Group('integration')]
    public function a_follower_gets_the_last_news_then_every_new_one_until_the_delivery_ends(): void
    {
        $code = self::freshTrackingCode();
        self::assertSame(202, self::report(self::position($code, 3120)));

        $frames = InCoroutine::run(static function () use ($code): array {
            $client = self::follower();
            self::assertTrue($client->upgrade('/v1/live?trackingCode=' . $code), 'the handshake of a valid code switches protocols');
            $frames = [self::receive($client)];
            self::report(self::position($code, 2400));
            $frames[] = self::receive($client);
            self::report(['type' => 'ended', 'trackingCode' => $code, 'outcome' => 'delivered', 'at' => '2026-09-28T21:56:33.101Z']);
            $frames[] = self::receive($client);
            $close = $client->recv(3);
            $frames[] = $close instanceof CloseFrame ? $close->code : 'no close frame';

            return $frames;
        });

        self::assertStringContainsString('"remainingMeters":3120', (string) $frames[0], 'the last news comes first');
        self::assertStringContainsString('"remainingMeters":2400', (string) $frames[1]);
        self::assertStringContainsString('"type":"ended"', (string) $frames[2]);
        self::assertSame(1000, $frames[3]);
    }

    #[Test]
    #[Group('integration')]
    public function a_handshake_without_a_tracking_code_gets_a_problem(): void
    {
        $response = self::exchange([
            'GET /v1/live?trackingCode=nope HTTP/1.1',
            'Upgrade: websocket',
            'Connection: Upgrade',
            'Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version: 13',
        ]);

        self::assertStringStartsWith('HTTP/1.1 422', $response);
        self::assertStringContainsString('"trackingCode"', $response);
    }

    #[Test]
    #[Group('integration')]
    public function on_sigterm_followers_are_told_to_come_back_and_the_server_still_stops_cleanly(): void
    {
        $code = self::freshTrackingCode();
        $server = $this->server;
        self::assertIsResource($server);

        $closeCode = InCoroutine::run(static function () use ($code, $server): int|string {
            $client = self::follower();
            self::assertTrue($client->upgrade('/v1/live?trackingCode=' . $code));
            proc_terminate($server, self::SIGTERM);
            $frame = $client->recv(5);

            return $frame instanceof CloseFrame ? $frame->code : 'no close frame';
        });

        self::assertSame(1001, $closeCode, 'going away: the client connects again elsewhere');
        self::assertSame(0, $this->waitForExit());
    }

    #[Test]
    public function head_requests_get_the_headers_without_a_body(): void
    {
        [$head, $body] = explode("\r\n\r\n", self::exchange(['HEAD /health/live HTTP/1.1', 'Connection: close']), 2);

        self::assertStringStartsWith('HTTP/1.1 200', $head);
        self::assertStringContainsString("\r\nContent-Length: 15\r\n", $head . "\r\n");
        self::assertSame('', $body);
    }

    #[Test]
    #[Group('integration')]
    public function it_is_ready_when_redis_answers(): void
    {
        [$status, , $body] = self::get('/health/ready');

        self::assertSame(200, $status, $body);
        self::assertStringContainsString('"redis":{"status":"up"', $body);
    }

    #[Test]
    public function sigterm_stops_it_gracefully(): void
    {
        self::assertSame(0, $this->stop());

        $messages = array_column($this->logLines(), 'message');
        self::assertContains('Worker stopped', $messages);
        self::assertContains('Tracking server stopped', $messages);
    }

    #[Test]
    public function every_line_it_writes_is_json_with_the_service_and_the_correlation_id(): void
    {
        self::get('/health/live', 'log-test#1');
        $this->stop();

        $lines = $this->logLines();
        self::assertSame(['tracking'], array_values(array_unique(array_column($lines, 'service'))));
        $handled = array_values(array_filter($lines, static fn(array $line): bool => $line['message'] === 'Request handled'));
        self::assertSame('log-test#1', $handled[0]['correlation_id'] ?? null);
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $connection = @fsockopen(self::HOST, self::PORT, $errorCode, $errorMessage, 0.1);
            if ($connection !== false) {
                fclose($connection);

                return;
            }
            usleep(50_000);
        }

        self::fail('The server did not listen within 10 seconds: ' . file_get_contents($this->logFile));
    }

    private function stop(): int
    {
        self::assertIsResource($this->server);
        proc_terminate($this->server, self::SIGTERM);

        return $this->waitForExit();
    }

    private function waitForExit(): int
    {
        self::assertIsResource($this->server);
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->server);
            if (!$status['running']) {
                proc_close($this->server);
                $this->server = null;

                return $status['exitcode'];
            }
            usleep(50_000);
        }

        self::fail('The server did not stop within 10 seconds of SIGTERM.');
    }

    /** @return list<array<string, mixed>> every line must be a JSON object */
    private function logLines(): array
    {
        $lines = [];
        foreach (array_filter(explode("\n", (string) file_get_contents($this->logFile))) as $line) {
            $fields = json_decode($line, true);
            self::assertIsArray($fields, sprintf('Not a JSON log line: %s', $line));
            $lines[] = $fields;
        }

        return $lines;
    }

    /** @return array{int, array<string, string>, string} status, headers with lower-cased names and body */
    private static function get(string $path, ?string $correlationId = null): array
    {
        $context = stream_context_create(['http' => [
            'header' => $correlationId === null ? '' : 'X-Correlation-Id: ' . $correlationId,
            'ignore_errors' => true,
            'timeout' => 5,
        ]]);
        $body = (string) file_get_contents(sprintf('http://%s:%d%s', self::HOST, self::PORT, $path), false, $context);

        $status = 0;
        $headers = [];
        foreach (http_get_last_response_headers() ?? [] as $line) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            } elseif (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower($name)] = trim($value);
            }
        }

        return [$status, $headers, $body];
    }

    /**
     * Sends a raw HTTP request and returns the raw answer, read until the body is
     * complete or the server closes the connection.
     *
     * @param list<string> $requestLines request line first, then headers
     */
    private static function exchange(array $requestLines, string $body = ''): string
    {
        $socket = stream_socket_client(sprintf('tcp://%s:%d', self::HOST, self::PORT), $errorCode, $errorMessage, 5);
        self::assertIsResource($socket, (string) $errorMessage);
        stream_set_timeout($socket, 5);
        fwrite($socket, implode("\r\n", [$requestLines[0], 'Host: tracking', ...array_slice($requestLines, 1), '', '']) . $body);

        $response = '';
        while (!self::isComplete($response)) {
            $chunk = fread($socket, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
        }
        fclose($socket);

        return $response;
    }

    /**
     * Reports a delivery news the way a courier's device does, signed with the shared secret.
     *
     * @param array<string, int|float|string> $news
     */
    private static function report(array $news): int
    {
        $body = json_encode($news, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $now = time();
        $signature = sprintf('t=%d,v1=%s', $now, hash_hmac('sha256', $now . '.' . $body, self::COURIERS_SECRET));
        $response = self::exchange(['POST /v1/positions HTTP/1.1', 'Content-Type: application/json', 'Content-Length: ' . strlen($body), 'Courier-Signature: ' . $signature], $body);

        return preg_match('#^HTTP/\S+ (\d{3})#', $response, $match) === 1 ? (int) $match[1] : 0;
    }

    /** @return array<string, int|float|string> */
    private static function position(string $code, int $remainingMeters): array
    {
        return ['type' => 'position', 'trackingCode' => $code, 'latitude' => -19.9112, 'longitude' => -44.0321, 'at' => '2026-09-28T21:56:13.634Z', 'remainingMeters' => $remainingMeters];
    }

    /** A code no earlier run left news for in Redis. */
    private static function freshTrackingCode(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $code = 'TX';
        for ($i = 0; $i < 13; $i++) {
            $code .= $alphabet[random_int(0, 31)];
        }

        return $code;
    }

    /** A client that hands the close frame over, with its code, the way a browser's close event does. */
    private static function follower(): Client
    {
        $client = new Client(self::HOST, self::PORT);
        $client->set(['open_websocket_close_frame' => true]);

        return $client;
    }

    private static function receive(Client $client): string
    {
        $frame = $client->recv(3);

        return $frame instanceof Frame ? $frame->data : 'nothing received';
    }

    private static function isComplete(string $response): bool
    {
        $headerEnd = strpos($response, "\r\n\r\n");
        if ($headerEnd === false || preg_match('/^Content-Length: (\d+)/mi', $response, $match) !== 1) {
            return false;
        }

        return strlen($response) >= $headerEnd + 4 + (int) $match[1];
    }
}
