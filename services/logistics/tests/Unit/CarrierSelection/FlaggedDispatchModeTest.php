<?php

declare(strict_types=1);

namespace Tests\Unit\CarrierSelection;

use Logistics\CarrierSelection\Adapter\Driven\FlaggedDispatchMode;
use Logistics\CarrierSelection\Domain\DispatchMode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\InMemoryFlags;

final class FlaggedDispatchModeTest extends TestCase
{
    #[Test]
    public function the_ops_toggle_puts_the_own_fleet_first(): void
    {
        self::assertSame(DispatchMode::OwnFleetFirst, (new FlaggedDispatchMode(new InMemoryFlags(['logistics.own-fleet-dispatch' => true])))->current());
        self::assertSame(DispatchMode::PartnersOnly, (new FlaggedDispatchMode(new InMemoryFlags(['logistics.own-fleet-dispatch' => false])))->current());
    }

    #[Test]
    public function when_the_flags_do_not_answer_the_partners_take_everything(): void
    {
        self::assertSame(DispatchMode::PartnersOnly, (new FlaggedDispatchMode(new InMemoryFlags()))->current());
    }
}
