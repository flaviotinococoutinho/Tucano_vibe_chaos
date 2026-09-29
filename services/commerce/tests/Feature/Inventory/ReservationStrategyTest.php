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
            'store' => 'arara',
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
    public function in_the_lab_a_preference_picks_the_strategy_of_one_request(): void
    {
        $this->flags->set('labs.enabled', true);

        $this->placeOrder(['Prefer' => 'respond-async, Reservation-Strategy="optimistic"; lab=overselling'])
            ->assertCreated()
            ->assertHeader('Preference-Applied', 'reservation-strategy=optimistic');

        self::assertSame(ReservationStrategy::Optimistic, $this->chosen());
    }

    #[Test]
    public function outside_the_lab_the_preference_is_ignored_and_the_answer_says_nothing(): void
    {
        $this->placeOrder(['Prefer' => 'reservation-strategy=naive'])
            ->assertCreated()
            ->assertHeaderMissing('Preference-Applied');

        self::assertSame(ReservationStrategy::Atomic, $this->chosen());
    }

    #[Test]
    public function a_strategy_that_does_not_exist_is_a_hint_the_server_ignores(): void
    {
        $this->flags->set('labs.enabled', true);
        $this->flags->set('inventory.reservation-strategy', 'pessimistic');

        $this->placeOrder(['Prefer' => 'reservation-strategy=magic'])
            ->assertCreated()
            ->assertHeaderMissing('Preference-Applied');

        self::assertSame(ReservationStrategy::Pessimistic, $this->chosen());
    }

    #[Test]
    public function only_the_first_of_a_repeated_preference_counts(): void
    {
        $this->flags->set('labs.enabled', true);

        $this->placeOrder(['Prefer' => 'reservation-strategy=naive, reservation-strategy=serializable'])
            ->assertHeader('Preference-Applied', 'reservation-strategy=naive');

        self::assertSame(ReservationStrategy::Naive, $this->chosen());
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
            'store' => 'arara',
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
