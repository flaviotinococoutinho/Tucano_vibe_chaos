<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Domain\AggregateRoot;
use Tucano\SharedKernel\Tests\Doubles\OrderPaid;

#[CoversClass(AggregateRoot::class)]
final class AggregateRootTest extends TestCase
{
    #[Test]
    public function recorded_events_are_released_only_once(): void
    {
        $order = new class extends AggregateRoot {
            public function pay(): void
            {
                $this->recordThat(new OrderPaid('01926f3a-8c1e-7b2d-9f4a-3c5e6d7f8a9b', 'order-1', new DateTimeImmutable()));
            }
        };
        $order->pay();

        self::assertCount(1, $order->releaseEvents());
        self::assertSame([], $order->releaseEvents());
    }
}
