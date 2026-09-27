<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Identity;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Identity\UuidIdentifier;
use Tucano\SharedKernel\Tests\Doubles\OrderId;
use Tucano\SharedKernel\Tests\Doubles\ShipmentId;

#[CoversClass(UuidIdentifier::class)]
final class UuidIdentifierTest extends TestCase
{
    #[Test]
    public function it_generates_a_version_7_uuid(): void
    {
        $id = OrderId::generate();

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id->toString());
    }

    #[Test]
    public function it_knows_when_it_was_created(): void
    {
        $before = new DateTimeImmutable('-1 second');
        $createdAt = OrderId::generate()->createdAt();

        self::assertGreaterThanOrEqual($before, $createdAt);
        self::assertLessThanOrEqual(new DateTimeImmutable('+1 second'), $createdAt);
    }

    #[Test]
    public function it_refuses_a_uuid_that_is_not_version_7(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OrderId::fromString('9b2e5c9a-3f1d-4c8e-9a7b-1d2e3f4a5b6c');
    }

    #[Test]
    public function it_survives_a_round_trip_through_binary(): void
    {
        $id = OrderId::generate();

        self::assertTrue(OrderId::fromBytes($id->toBytes())->equals($id));
        self::assertSame(16, strlen($id->toBytes()));
    }

    #[Test]
    public function it_normalizes_to_lowercase(): void
    {
        $id = OrderId::generate();

        self::assertTrue(OrderId::fromString(strtoupper($id->toString()))->equals($id));
    }

    #[Test]
    public function identities_of_different_aggregates_are_never_equal(): void
    {
        $value = OrderId::generate()->toString();

        self::assertFalse(OrderId::fromString($value)->equals(ShipmentId::fromString($value)));
    }
}
