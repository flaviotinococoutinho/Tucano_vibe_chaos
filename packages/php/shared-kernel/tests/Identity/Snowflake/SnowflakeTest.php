<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Identity\Snowflake;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

#[CoversClass(Snowflake::class)]
#[CoversClass(NodeId::class)]
final class SnowflakeTest extends TestCase
{
    /** 2026-09-27T12:00:04.567Z */
    private const int MILLIS = 1_790_510_404_567;

    #[Test]
    public function it_packs_time_node_and_sequence_into_64_bits(): void
    {
        $snowflake = Snowflake::compose(self::MILLIS, new NodeId(1, 12), 3);

        self::assertSame(97663548934766595, $snowflake->toInt());
    }

    #[Test]
    public function it_explains_itself(): void
    {
        $snowflake = Snowflake::fromBase32('02PQRFBTW5G03');

        self::assertSame('2026-09-27T12:00:04.567Z', $snowflake->createdAt()->format('Y-m-d\TH:i:s.v\Z'));
        self::assertEquals(new NodeId(1, 12), $snowflake->node());
        self::assertSame(3, $snowflake->sequence());
    }

    #[Test]
    public function it_sorts_by_creation_time(): void
    {
        $earlier = Snowflake::compose(self::MILLIS, new NodeId(31, 31), 4095);
        $later = Snowflake::compose(self::MILLIS + 1, new NodeId(0, 0), 0);

        self::assertLessThan($later->toInt(), $earlier->toInt());
    }

    #[Test]
    public function it_round_trips_through_every_format(): void
    {
        $snowflake = Snowflake::compose(self::MILLIS, new NodeId(1, 11), 42);

        self::assertTrue(Snowflake::fromString($snowflake->toString())->equals($snowflake));
        self::assertTrue(Snowflake::fromBase32($snowflake->toBase32())->equals($snowflake));
        self::assertTrue(Snowflake::fromInt($snowflake->toInt())->equals($snowflake));
    }

    #[Test]
    public function it_is_serialized_to_json_as_a_string(): void
    {
        $snowflake = Snowflake::compose(self::MILLIS, new NodeId(1, 12), 3);

        self::assertSame('{"id":"97663548934766595"}', json_encode(['id' => $snowflake]));
    }

    #[Test]
    public function it_refuses_timestamps_before_the_epoch(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Snowflake::compose(Snowflake::EPOCH_MILLIS - 1, new NodeId(1, 1), 0);
    }

    #[Test]
    public function it_refuses_sequences_that_do_not_fit_in_12_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Snowflake::compose(self::MILLIS, new NodeId(1, 1), 4096);
    }

    #[Test]
    public function it_refuses_decimals_that_overflow_64_bits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Snowflake::fromString('92233720368547758070');
    }

    #[Test]
    public function node_ids_have_five_bits_each(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NodeId(1, 32);
    }
}
