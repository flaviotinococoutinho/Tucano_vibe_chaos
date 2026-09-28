<?php

declare(strict_types=1);

namespace Tests\Unit\CarrierSelection;

use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\UseCase\ChooseCarrier;
use Logistics\CarrierSelection\Domain\DispatchMode;
use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\CarrierSelection\FixedDispatchMode;
use Tests\Doubles\CarrierSelection\InMemoryCarriers;
use Tests\Doubles\CarrierSelection\InMemoryFulfillmentCenters;

final class ChooseCarrierTest extends TestCase
{
    private FixedDispatchMode $dispatch;

    private ChooseCarrier $chooseCarrier;

    protected function setUp(): void
    {
        $this->dispatch = new FixedDispatchMode(DispatchMode::OwnFleetFirst);
        $this->chooseCarrier = new ChooseCarrier(new InMemoryCarriers(), new InMemoryFulfillmentCenters(), $this->dispatch);
    }

    #[Test]
    public function the_own_fleet_delivers_inside_the_state_of_the_center(): void
    {
        self::assertSame('tucano-express', $this->chooseCarrier->choose(new CarrierRequest('GRU1', 'SP', 1_100))->code);
        self::assertSame('tucano-express', $this->chooseCarrier->choose(new CarrierRequest('BHZ1', 'MG', 1_100))->code);
    }

    #[Test]
    public function the_state_of_the_center_is_what_counts(): void
    {
        self::assertSame('correio-nacional', $this->chooseCarrier->choose(new CarrierRequest('BHZ1', 'SP', 1_100))->code);
    }

    #[Test]
    public function with_partners_only_the_partners_take_everything(): void
    {
        $this->dispatch->mode = DispatchMode::PartnersOnly;

        self::assertSame('correio-nacional', $this->chooseCarrier->choose(new CarrierRequest('GRU1', 'SP', 1_100))->code);
    }

    #[Test]
    public function a_center_outside_the_table_is_refused(): void
    {
        $this->expectExceptionObject(UnknownFulfillmentCenter::withCode('POA1'));

        $this->chooseCarrier->choose(new CarrierRequest('POA1', 'RS', 1_100));
    }
}
