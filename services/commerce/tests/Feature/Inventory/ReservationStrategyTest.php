<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Commerce\Inventory\Application\Port\Driven\ForChoosingStrategy;
use Commerce\Inventory\Application\ReservationStrategy;
use Database\Seeders\FulfillmentCenterSeeder;
use Database\Seeders\StockSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Builders\Addresses;
use Tests\TestCase;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\InMemoryFlags;

#[Group('integration')]
final class ReservationStrategyTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryFlags $flags;

    protected function setUp(): void
    {
        parent::setUp();
        // Unguarded on purpose: APP_ENV=testing counts as production, where labs.* is always off.
        $this->flags = new InMemoryFlags();
        $this->app->instance(FeatureFlags::class, $this->flags);
        $this->seed([FulfillmentCenterSeeder::class, StockSeeder::class]);
        DB::table('product_snapshots')->insert([
            'product_id' => (string) Str::uuid7(),
            'sku' => 'BOOK-DDD-001',
            'name' => 'Domain-Driven Design',
            'price_cents' => 18990,
            'currency' => 'BRL',
            'status' => 'active',
            'catalog_version' => 1,
        ]);
    }

    #[Test]
    public function the_flag_picks_the_strategy(): void
    {
        $this->flags->set('inventory.reservation-strategy', 'pessimistic');

        $this->placeOrder()->assertCreated();

        self::assertSame(ReservationStrategy::Pessimistic, $this->chosen());
    }

    #[Test]
    public function in_the_lab_a_header_picks_the_strategy_of_one_request(): void
    {
        $this->flags->set('labs.enabled', true);

        $this->placeOrder(['X-Inventory-Strategy' => 'optimistic'])->assertCreated();

        self::assertSame(ReservationStrategy::Optimistic, $this->chosen());
    }

    #[Test]
    public function outside_the_lab_the_header_is_ignored(): void
    {
        $this->placeOrder(['X-Inventory-Strategy' => 'naive'])->assertCreated();

        self::assertSame(ReservationStrategy::Atomic, $this->chosen());
    }

    #[Test]
    public function a_strategy_that_does_not_exist_is_a_bad_request(): void
    {
        $this->flags->set('labs.enabled', true);

        $this->placeOrder(['X-Inventory-Strategy' => 'magic'])
            ->assertBadRequest()
            ->assertJsonPath('detail', 'X-Inventory-Strategy must be one of atomic, pessimistic, optimistic, serializable, naive.');
    }

    #[Test]
    public function an_unknown_value_in_the_flags_falls_back_to_atomic(): void
    {
        $this->flags->set('inventory.reservation-strategy', 'redis');

        $this->placeOrder()->assertCreated();

        self::assertSame(ReservationStrategy::Atomic, $this->chosen());
    }

    /**
     * @param array<string, string> $headers
     *
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function placeOrder(array $headers = []): TestResponse
    {
        return $this->postJson('/v1/orders', [
            'customer' => ['id' => (string) Str::uuid7(), 'name' => 'Ana Souza', 'email' => 'ana@example.com'],
            'shippingAddress' => Addresses::paulista()->toArray(),
            'items' => [['sku' => 'BOOK-DDD-001', 'quantity' => 1]],
        ], ['Idempotency-Key' => (string) Str::uuid7(), ...$headers]);
    }

    private function chosen(): ReservationStrategy
    {
        return $this->app->make(ForChoosingStrategy::class)->current();
    }
}
