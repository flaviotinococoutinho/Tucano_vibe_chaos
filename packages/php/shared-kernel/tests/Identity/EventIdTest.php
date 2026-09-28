<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Identity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Identity\EventId;

#[CoversClass(EventId::class)]
final class EventIdTest extends TestCase
{
    #[Test]
    public function every_event_gets_its_own_version_7_id_in_the_order_they_happen(): void
    {
        $first = EventId::generate();
        $second = EventId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first->toString());
        self::assertFalse($first->equals($second));
        self::assertLessThanOrEqual($second->createdAt(), $first->createdAt());
    }
}
