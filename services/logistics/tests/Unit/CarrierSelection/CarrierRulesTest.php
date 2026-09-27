<?php

declare(strict_types=1);

namespace Tests\Unit\CarrierSelection;

use Logistics\CarrierSelection\Domain\Carrier;
use Logistics\CarrierSelection\Domain\CarrierKind;
use Logistics\CarrierSelection\Domain\Carriers;
use Logistics\CarrierSelection\Domain\Consignment;
use Logistics\CarrierSelection\Domain\NoCarrierFits;
use Logistics\CarrierSelection\Domain\Rule\CarrierRule;
use Logistics\CarrierSelection\Domain\Rule\HeavyFreight;
use Logistics\CarrierSelection\Domain\Rule\OwnFleet;
use Logistics\CarrierSelection\Domain\Rule\RegularPartners;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\CarrierSelection\InMemoryCarriers;

/** The carrier chain over the seeded carriers: tucano-express 20 kg, ligeirinho and correio-nacional 30 kg, carga-pesada 1 t. */
final class CarrierRulesTest extends TestCase
{
    /** @return iterable<string, array{Consignment, string}> */
    public static function consignments(): iterable
    {
        yield 'inside the state, light: the own fleet' => [new Consignment('SP', 'SP', 1_100), 'tucano-express'];
        yield 'inside the state, right at the fleet limit' => [new Consignment('SP', 'SP', 20_000), 'tucano-express'];
        yield 'inside the state, over the fleet limit: the smallest partner, the code breaks the tie' => [new Consignment('SP', 'SP', 20_001), 'correio-nacional'];
        yield 'to another state, even when light: a partner' => [new Consignment('SP', 'MG', 1_100), 'correio-nacional'];
        yield 'over every regular partner: heavy freight' => [new Consignment('SP', 'MG', 30_001), 'carga-pesada'];
        yield 'right at the heavy freight limit' => [new Consignment('MG', 'MG', 1_000_000), 'carga-pesada'];
    }

    #[Test]
    #[DataProvider('consignments')]
    public function the_first_link_with_a_carrier_that_fits_decides(Consignment $consignment, string $carrier): void
    {
        self::assertSame($carrier, self::chain()->choose($consignment, InMemoryCarriers::seeded())->code);
    }

    #[Test]
    public function nothing_fits_above_the_heaviest_carrier(): void
    {
        $this->expectExceptionObject(NoCarrierFits::for(new Consignment('SP', 'BA', 1_000_001)));

        self::chain()->choose(new Consignment('SP', 'BA', 1_000_001), InMemoryCarriers::seeded());
    }

    #[Test]
    public function without_its_link_the_own_fleet_is_never_chosen(): void
    {
        $chain = new RegularPartners(new HeavyFreight());

        self::assertSame('correio-nacional', $chain->choose(new Consignment('SP', 'SP', 1_100), InMemoryCarriers::seeded())->code);
    }

    #[Test]
    public function the_partner_with_the_smallest_limit_that_still_fits_wins(): void
    {
        $carriers = new Carriers(
            new Carrier('frete-grande', CarrierKind::Partner, 50_000),
            new Carrier('frete-medio', CarrierKind::Partner, 25_000),
            new Carrier('frete-pequeno', CarrierKind::Partner, 5_000),
        );

        self::assertSame('frete-medio', new RegularPartners()->choose(new Consignment('SP', 'RJ', 6_000), $carriers)->code);
    }

    #[Test]
    public function heavy_freight_waits_until_no_regular_partner_fits(): void
    {
        $carriers = new Carriers(
            new Carrier(HeavyFreight::CARRIER, CarrierKind::Partner, 1_000_000),
            new Carrier('frete-enorme', CarrierKind::Partner, 2_000_000),
        );

        self::assertSame('frete-enorme', self::chain()->choose(new Consignment('SP', 'RJ', 40_000), $carriers)->code);
    }

    #[Test]
    public function the_order_of_the_table_does_not_matter(): void
    {
        $reversed = new Carriers(
            new Carrier('carga-pesada', CarrierKind::Partner, 1_000_000),
            new Carrier('correio-nacional', CarrierKind::Partner, 30_000),
            new Carrier('ligeirinho', CarrierKind::Partner, 30_000),
            new Carrier('tucano-express', CarrierKind::OwnFleet, 20_000),
        );

        self::assertSame('correio-nacional', self::chain()->choose(new Consignment('SP', 'MG', 1_100), $reversed)->code);
    }

    private static function chain(): CarrierRule
    {
        return new OwnFleet(new RegularPartners(new HeavyFreight()));
    }
}
