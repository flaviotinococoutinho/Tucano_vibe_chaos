<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Identity\Snowflake;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Identity\Snowflake\ClockMovedBackwards;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Sequence\InMemorySequence;
use Tucano\SharedKernel\Identity\Snowflake\Sequence\SequenceProvider;
use Tucano\SharedKernel\Identity\Snowflake\SnowflakeGenerator;
use Tucano\SharedKernel\Tests\Doubles\SteppingClock;
use Tucano\SharedKernel\Time\FrozenClock;

#[CoversClass(SnowflakeGenerator::class)]
#[CoversClass(InMemorySequence::class)]
#[CoversClass(ClockMovedBackwards::class)]
final class SnowflakeGeneratorTest extends TestCase
{
    #[Test]
    public function ids_in_the_same_millisecond_get_consecutive_sequences(): void
    {
        $generator = new SnowflakeGenerator(new NodeId(1, 11), new InMemorySequence(), new FrozenClock('2026-09-27T12:00:00.000Z'));

        $first = $generator->next();
        $second = $generator->next();

        self::assertSame(0, $first->sequence());
        self::assertSame(1, $second->sequence());
        self::assertGreaterThan($first->toInt(), $second->toInt());
    }

    #[Test]
    public function the_sequence_restarts_on_a_new_millisecond(): void
    {
        $clock = new SteppingClock('2026-09-27T12:00:00.000Z', '2026-09-27T12:00:00.000Z', '2026-09-27T12:00:00.001Z');
        $generator = new SnowflakeGenerator(new NodeId(1, 11), new InMemorySequence(), $clock);

        $generator->next();
        $generator->next();
        $third = $generator->next();

        self::assertSame(0, $third->sequence());
        self::assertSame('2026-09-27T12:00:00.001Z', $third->createdAt()->format('Y-m-d\TH:i:s.v\Z'));
    }

    #[Test]
    public function it_waits_for_the_next_millisecond_when_the_sequence_runs_out(): void
    {
        $exhausted = new class implements SequenceProvider {
            public function next(int $millis): int
            {
                return $millis % 1000 === 0 ? 4096 : 0;
            }
        };
        $clock = new SteppingClock('2026-09-27T12:00:00.000Z', '2026-09-27T12:00:00.000Z', '2026-09-27T12:00:00.002Z');
        $generator = new SnowflakeGenerator(new NodeId(1, 11), $exhausted, $clock);

        $snowflake = $generator->next();

        self::assertSame('2026-09-27T12:00:00.002Z', $snowflake->createdAt()->format('Y-m-d\TH:i:s.v\Z'));
    }

    #[Test]
    public function it_refuses_to_generate_ids_when_the_clock_moves_backwards(): void
    {
        $clock = new SteppingClock('2026-09-27T12:00:00.010Z', '2026-09-27T12:00:00.004Z');
        $generator = new SnowflakeGenerator(new NodeId(1, 11), new InMemorySequence(), $clock);
        $generator->next();

        $this->expectException(ClockMovedBackwards::class);
        $this->expectExceptionMessage('6 ms');

        $generator->next();
    }

    #[Test]
    public function ids_carry_the_node_that_generated_them(): void
    {
        $generator = new SnowflakeGenerator(new NodeId(1, 12), new InMemorySequence(), new FrozenClock());

        self::assertEquals(new NodeId(1, 12), $generator->next()->node());
    }
}
