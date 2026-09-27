<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class CorrelationIdTest extends TestCase
{
    #[Test]
    public function it_keeps_the_id_that_came_from_the_gateway(): void
    {
        $this->json('GET', '/health/live', [], ['X-Correlation-Id' => '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12']);

        $this->response->assertHeader('X-Correlation-Id', '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12');
    }

    #[Test]
    public function it_creates_one_when_the_request_has_none(): void
    {
        $this->json('GET', '/health/live');

        $header = (string) $this->response->headers->get('X-Correlation-Id');
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $header);
    }

    #[Test]
    public function error_responses_quote_the_id_it_created(): void
    {
        $this->json('GET', '/v1/nothing-here');

        $header = (string) $this->response->headers->get('X-Correlation-Id');
        self::assertNotSame('', $header);
        $this->response->assertJsonPath('correlationId', $header);
    }

    #[Test]
    public function every_log_line_carries_the_service_and_the_id(): void
    {
        $stream = (string) tempnam(sys_get_temp_dir(), 'catalog-log-');
        config(['logging.default' => 'stderr', 'logging.channels.stderr.handler_with.stream' => $stream]);
        $this->app->router->get('/test/log', function (LoggerInterface $logger): string {
            $logger->info('Price list served.');

            return 'ok';
        });

        $this->json('GET', '/test/log', [], ['X-Correlation-Id' => 'req-7#3']);

        $line = json_decode((string) file_get_contents($stream), true, flags: JSON_THROW_ON_ERROR);
        unlink($stream);
        self::assertIsArray($line);
        self::assertSame('Price list served.', $line['message']);
        self::assertSame(['service' => 'catalog', 'correlation_id' => 'req-7#3'], $line['extra']);
    }
}
