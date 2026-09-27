<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JitteredTtl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class JitteredTtlTest extends TestCase
{
    private const int SAMPLES = 2_000;

    #[Test]
    public function it_never_leaves_ten_percent_around_the_base(): void
    {
        $samples = $this->sample(new JitteredTtl(300, 0.1));

        self::assertGreaterThanOrEqual(270, min($samples));
        self::assertLessThanOrEqual(330, max($samples));
    }

    #[Test]
    public function it_spreads_expiry_over_the_whole_window(): void
    {
        $samples = $this->sample(new JitteredTtl(300, 0.1, new Randomizer(new Mt19937(7))));

        self::assertSame(270, min($samples));
        self::assertSame(330, max($samples));
        self::assertCount(61, array_unique($samples));
    }

    #[Test]
    public function without_spread_it_is_the_base(): void
    {
        self::assertSame([30], array_values(array_unique($this->sample(new JitteredTtl(30, 0.0)))));
    }

    /** @return non-empty-list<int> */
    private function sample(JitteredTtl $ttl): array
    {
        $samples = [$ttl->seconds()];
        for ($i = 1; $i < self::SAMPLES; $i++) {
            $samples[] = $ttl->seconds();
        }

        return $samples;
    }
}
