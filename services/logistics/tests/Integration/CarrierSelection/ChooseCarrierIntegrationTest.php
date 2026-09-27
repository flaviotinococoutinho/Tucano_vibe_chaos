<?php

declare(strict_types=1);

namespace Tests\Integration\CarrierSelection;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Logistics\CarrierSelection\Application\CarrierRequest;
use Logistics\CarrierSelection\Application\Port\Driving\ForChoosingCarriers;
use Logistics\CarrierSelection\Domain\NoCarrierFits;
use Logistics\CarrierSelection\Domain\UnknownFulfillmentCenter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\InMemoryFlags;

/** The carrier chain over the carriers and fulfillment_centers tables as the seeders fill them. */
#[Group('integration')]
final class ChooseCarrierIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(FeatureFlags::class, new InMemoryFlags(['logistics.own-fleet-dispatch' => true]));
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
    }

    /** @return iterable<string, array{CarrierRequest, string}> */
    public static function requests(): iterable
    {
        yield 'from Guarulhos to São Paulo' => [new CarrierRequest('GRU1', 'SP', 1_100), 'tucano-express'];
        yield 'from Contagem to Minas Gerais' => [new CarrierRequest('BHZ1', 'MG', 1_100), 'tucano-express'];
        yield 'from Guarulhos to Minas Gerais' => [new CarrierRequest('GRU1', 'MG', 1_100), 'correio-nacional'];
        yield 'too heavy for the vans' => [new CarrierRequest('GRU1', 'SP', 25_000), 'correio-nacional'];
        yield 'too heavy for the partners' => [new CarrierRequest('GRU1', 'BA', 500_000), 'carga-pesada'];
    }

    #[Test]
    #[DataProvider('requests')]
    public function the_chain_runs_over_the_carriers_table(CarrierRequest $request, string $carrier): void
    {
        self::assertSame($carrier, $this->app->make(ForChoosingCarriers::class)->choose($request)->code);
    }

    #[Test]
    public function the_limits_come_from_the_table(): void
    {
        DB::table('carriers')->where('code', 'tucano-express')->update(['max_weight_grams' => 500]);

        self::assertSame('correio-nacional', $this->app->make(ForChoosingCarriers::class)->choose(new CarrierRequest('GRU1', 'SP', 1_100))->code);
    }

    #[Test]
    public function nothing_takes_two_tonnes(): void
    {
        $this->expectException(NoCarrierFits::class);

        $this->app->make(ForChoosingCarriers::class)->choose(new CarrierRequest('GRU1', 'SP', 2_000_000));
    }

    #[Test]
    public function a_center_outside_the_table_is_refused(): void
    {
        $this->expectException(UnknownFulfillmentCenter::class);

        $this->app->make(ForChoosingCarriers::class)->choose(new CarrierRequest('POA1', 'RS', 1_100));
    }
}
