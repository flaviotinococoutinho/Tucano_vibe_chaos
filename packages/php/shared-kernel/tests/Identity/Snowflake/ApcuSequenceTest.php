<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Identity\Snowflake;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Identity\Snowflake\Sequence\ApcuSequence;

#[CoversClass(ApcuSequence::class)]
#[RequiresPhpExtension('apcu')]
final class ApcuSequenceTest extends TestCase
{
    protected function setUp(): void
    {
        if (!apcu_enabled()) {
            self::markTestSkipped('APCu is loaded but disabled (apc.enable_cli=0).');
        }
        apcu_clear_cache();
    }

    #[Test]
    public function it_counts_within_a_millisecond_and_restarts_on_the_next(): void
    {
        $sequence = new ApcuSequence('snowflake:1:1');

        self::assertSame(0, $sequence->next(1_790_510_400_000));
        self::assertSame(1, $sequence->next(1_790_510_400_000));
        self::assertSame(0, $sequence->next(1_790_510_400_001));
    }

    #[Test]
    public function different_workers_never_share_a_counter(): void
    {
        $worker1 = new ApcuSequence('snowflake:1:1');
        $worker2 = new ApcuSequence('snowflake:1:2');

        $worker1->next(1_790_510_400_000);

        self::assertSame(0, $worker2->next(1_790_510_400_000));
    }
}
