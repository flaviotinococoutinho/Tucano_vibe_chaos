<?php

declare(strict_types=1);

namespace Tests\Unit\CarrierSelection;

use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\UseCase\ChooseCarrier;
use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\CarrierSelection\InMemoryCarriers;
use Tests\Doubles\CarrierSelection\InMemoryFulfillmentCenters;
use Tucano\FeatureFlags\InMemoryFlags;

final class ChooseCarrierTest extends TestCase
{
    private InMemoryFlags $flags;

    private ChooseCarrier $chooseCarrier;

    protected function setUp(): void
    {
        $this->flags = new InMemoryFlags(['logistics.own-fleet-dispatch' => true]);
        $this->chooseCarrier = new ChooseCarrier(new InMemoryCarriers(), new InMemoryFulfillmentCenters(), $this->flags);
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
    public function with_the_flag_off_the_partners_take_everything(): void
    {
        $this->flags->set('logistics.own-fleet-dispatch', false);

        self::assertSame('correio-nacional', $this->chooseCarrier->choose(new CarrierRequest('GRU1', 'SP', 1_100))->code);
    }

    #[Test]
    public function when_the_flags_do_not_answer_the_partners_take_everything(): void
    {
        $chooseCarrier = new ChooseCarrier(new InMemoryCarriers(), new InMemoryFulfillmentCenters(), new InMemoryFlags());

        self::assertSame('correio-nacional', $chooseCarrier->choose(new CarrierRequest('GRU1', 'SP', 1_100))->code);
    }

    #[Test]
    public function a_center_outside_the_table_is_refused(): void
    {
        $this->expectExceptionObject(UnknownFulfillmentCenter::withCode('POA1'));

        $this->chooseCarrier->choose(new CarrierRequest('POA1', 'RS', 1_100));
    }
}
