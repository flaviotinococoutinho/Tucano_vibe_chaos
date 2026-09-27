<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Http\Request as SwooleRequest;
use Tracking\Platform\Http\SwooleBridge;

final class SwooleBridgeTest extends TestCase
{
    #[Test]
    public function it_reads_method_path_query_and_headers_from_a_swoole_request(): void
    {
        $swooleRequest = SwooleRequest::create();
        $swooleRequest->parse("GET /v1/couriers/nearest?lat=-23.55&lng=-46.63 HTTP/1.1\r\nHost: tracking\r\nX-Correlation-Id: 4f1c2b7e#7\r\n\r\n");

        $request = SwooleBridge::request($swooleRequest);

        self::assertSame('GET', $request->method);
        self::assertSame('/v1/couriers/nearest', $request->path);
        self::assertSame('lat=-23.55&lng=-46.63', $request->query);
        self::assertSame('4f1c2b7e#7', $request->header('X-Correlation-Id'));
    }
}
