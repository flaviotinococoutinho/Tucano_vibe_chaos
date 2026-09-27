<?php

declare(strict_types=1);

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\WaitGroup;
use Tests\Support\InCoroutine;
use Tracking\Platform\RequestContext;

final class RequestContextTest extends TestCase
{
    #[Test]
    public function concurrent_requests_each_see_their_own_correlation_id(): void
    {
        $seen = InCoroutine::run(static function (): array {
            $seen = [];
            $requests = new WaitGroup();
            foreach (['request-a#1', 'request-b#1'] as $correlationId) {
                $requests->add();
                Coroutine::create(static function () use ($correlationId, $requests, &$seen): void {
                    RequestContext::bindCorrelationId($correlationId);
                    // Yields, so the other request runs in between, like two clients on the same worker.
                    Coroutine::sleep(0.01);
                    $seen[$correlationId] = RequestContext::correlationId();
                    $requests->done();
                });
            }
            $requests->wait();

            return $seen;
        });

        self::assertEquals(['request-a#1' => 'request-a#1', 'request-b#1' => 'request-b#1'], $seen);
    }

    #[Test]
    public function coroutines_started_by_a_request_see_its_correlation_id(): void
    {
        $seen = InCoroutine::run(static function (): ?string {
            RequestContext::bindCorrelationId('parent#1');
            $seen = null;
            $child = new WaitGroup(1);
            Coroutine::create(static function () use ($child, &$seen): void {
                $seen = RequestContext::correlationId();
                $child->done();
            });
            $child->wait();

            return $seen;
        });

        self::assertSame('parent#1', $seen);
    }

    #[Test]
    public function nothing_leaks_into_the_next_request(): void
    {
        $leaked = InCoroutine::run(static function (): ?string {
            Coroutine::create(static fn() => RequestContext::bindCorrelationId('finished#1'));
            $leaked = null;
            $next = new WaitGroup(1);
            Coroutine::create(static function () use ($next, &$leaked): void {
                $leaked = RequestContext::correlationId();
                $next->done();
            });
            $next->wait();

            return $leaked;
        });

        self::assertNull($leaked);
    }

    #[Test]
    public function there_is_no_correlation_id_outside_a_request(): void
    {
        self::assertNull(RequestContext::correlationId());
    }

    #[Test]
    public function binding_outside_a_coroutine_is_a_programming_error(): void
    {
        $this->expectException(LogicException::class);

        RequestContext::bindCorrelationId('nowhere#1');
    }
}
