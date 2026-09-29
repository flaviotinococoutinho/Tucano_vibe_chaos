<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use Commerce\Ordering\Adapter\Driven\MongoOrderViews;
use Commerce\Ordering\Adapter\Driving\Kafka\OrderViewProjector;
use Commerce\Ordering\Application\CustomerOrders;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Application\Port\Driven\ForReadingOrderViews;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Error\OrderListUnavailable;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\TrackingCode;
use Database\Seeders\FulfillmentCenterSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use MongoDB\Database;
use MongoDB\Driver\Exception\ConnectionTimeoutException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Tests\Builders\OrderBuilder;
use Tests\Builders\OrderEvents;
use Tests\Doubles\RecordingLogger;
use Tests\TestCase;

/** UC-ORD-05 for a customer: its order with the history, from PostgreSQL, and its list, from the read model. */
#[Group('integration')]
final class CustomerOrdersHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string ANA = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private const string BIA = '01999a1e-9f8e-7d6c-8b5a-493827160514';

    private ForStoringOrders $orders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FulfillmentCenterSeeder::class);
        $this->orders = $this->app->make(ForStoringOrders::class);
        $this->app->make(Database::class)->drop();
        self::assertSame(0, Artisan::call('mongo:migrate'));
    }

    #[Test]
    public function the_customer_sees_its_order_with_the_history_oldest_first(): void
    {
        $order = OrderBuilder::anOrder()->by(CustomerId::fromString(self::ANA))->placedAt('2026-09-27T12:00:00Z')->place();
        $this->orders->add($order);
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00.500Z'));
        $this->orders->save($order);
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $this->orders->save($order);

        $response = $this->getJson(sprintf('/v1/customers/%s/orders/%s', self::ANA, $order->id()->toString()))
            ->assertOk()
            ->assertJsonPath('status', 'shipped')
            ->assertJsonPath('trackingCode', 'TX02PWW6JFR5G00')
            ->assertJsonPath('history', [
                ['status' => 'pending_payment', 'at' => '2026-09-27T12:00:00.000+00:00', 'reason' => null],
                ['status' => 'paid', 'at' => '2026-09-27T12:05:00.500+00:00', 'reason' => null],
                ['status' => 'shipped', 'at' => '2026-09-27T13:00:00.000+00:00', 'reason' => null],
            ]);

        $this->getJson('/v1/orders/' . $order->id()->toString())->assertOk()->assertExactJson($response->json());
    }

    #[Test]
    public function a_cancelled_order_tells_why_in_its_history(): void
    {
        $order = OrderBuilder::anOrder()->by(CustomerId::fromString(self::ANA))->placedAt('2026-09-27T12:00:00Z')->place();
        $this->orders->add($order);
        $order->cancel(CancellationReason::PaymentDeclined, new DateTimeImmutable('2026-09-27T12:06:00Z'));
        $this->orders->save($order);

        $this->getJson(sprintf('/v1/customers/%s/orders/%s', self::ANA, $order->id()->toString()))
            ->assertOk()
            ->assertJsonPath('cancellationReason', 'payment_declined')
            ->assertJsonPath('history.1', ['status' => 'cancelled', 'at' => '2026-09-27T12:06:00.000+00:00', 'reason' => 'payment_declined']);
    }

    #[Test]
    public function another_customers_order_answers_like_an_order_that_does_not_exist(): void
    {
        $theirs = OrderBuilder::anOrder()->by(CustomerId::fromString(self::BIA))->place();
        $this->orders->add($theirs);
        $id = $theirs->id()->toString();

        $refused = $this->getJson(sprintf('/v1/customers/%s/orders/%s', self::ANA, $id))
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');

        self::assertSame(
            ['type' => 'about:blank', 'title' => 'Not Found', 'status' => 404, 'detail' => sprintf('Order %s does not exist.', $id)],
            array_intersect_key((array) $refused->json(), array_flip(['type', 'title', 'status', 'detail'])),
        );
        $unknown = Uuid::uuid7()->toString();
        self::assertSame(
            str_replace($unknown, $id, (string) json_encode(self::problemOf($this->getJson(sprintf('/v1/customers/%s/orders/%s', self::ANA, $unknown))->assertNotFound()->json()))),
            (string) json_encode(self::problemOf($refused->json())),
            'the same problem, word for word, as an order nobody placed',
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function idsThatCannotExist(): iterable
    {
        $order = '01999a2b-0000-7000-8000-000000000001';

        yield 'a customer id that is not a UUID' => ['not-a-customer', $order];
        yield 'a customer id that is a UUIDv4' => ['5f0c7a8e-1b2c-4d3e-8f4a-5b6c7d8e9f00', $order];
        yield 'an order id that is not a UUID' => [self::ANA, 'not-an-order'];
        yield 'an order id that is a UUIDv4' => [self::ANA, '5f0c7a8e-1b2c-4d3e-8f4a-5b6c7d8e9f00'];
    }

    #[Test]
    #[DataProvider('idsThatCannotExist')]
    public function ids_that_cannot_exist_are_not_found(string $customerId, string $orderId): void
    {
        $this->getJson(sprintf('/v1/customers/%s/orders/%s', $customerId, $orderId))
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', sprintf('Order %s does not exist.', $orderId));
    }

    #[Test]
    public function the_list_is_newest_first_one_page_at_a_time(): void
    {
        $ana = CustomerId::fromString(self::ANA);
        $first = $this->projected(OrderBuilder::anOrder()->by($ana)->placedAt('2026-09-27T12:00:00Z')->place());
        $second = $this->projected(OrderBuilder::anOrder()->by($ana)->placedAt('2026-09-27T13:00:00Z')->place());
        $third = $this->projected(OrderBuilder::anOrder()->by($ana)->placedAt('2026-09-27T14:00:00Z')->place());
        $this->projected(OrderBuilder::anOrder()->by(CustomerId::fromString(self::BIA))->placedAt('2026-09-27T15:00:00Z')->place());
        $second->markAsPaid(new DateTimeImmutable('2026-09-27T13:05:00Z'));
        $this->project($second);

        $this->getJson(sprintf('/v1/customers/%s/orders?page=1&perPage=2', self::ANA))
            ->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('perPage', 2)
            ->assertJsonPath('total', 3)
            ->assertJsonPath('orders.*.orderId', [$third->id()->toString(), $second->id()->toString()])
            ->assertJsonPath('orders.1', [
                'orderId' => $second->id()->toString(),
                'orderNumber' => (string) $second->toSnapshot()->number,
                'status' => 'paid',
                'cancellationReason' => null,
                'total' => ['amount' => 18990, 'currency' => 'BRL'],
                'lines' => [['sku' => 'BOOK-DDD-001', 'name' => 'Domain-Driven Design', 'quantity' => 1]],
                'placedAt' => '2026-09-27T13:00:00.000+00:00',
                'updatedAt' => '2026-09-27T13:05:00.000+00:00',
            ]);
        $this->getJson(sprintf('/v1/customers/%s/orders?page=2&perPage=2', self::ANA))
            ->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('orders.*.orderId', [$first->id()->toString()]);
        $this->getJson(sprintf('/v1/customers/%s/orders', self::ANA))
            ->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('perPage', 10)
            ->assertJsonCount(3, 'orders');
        $this->getJson(sprintf('/v1/customers/%s/orders?page=3&perPage=2', self::ANA))
            ->assertOk()
            ->assertExactJson(['page' => 3, 'perPage' => 2, 'total' => 3, 'orders' => []]);
    }

    #[Test]
    public function a_customer_with_no_orders_has_an_empty_list(): void
    {
        $this->getJson(sprintf('/v1/customers/%s/orders', Uuid::uuid7()->toString()))
            ->assertOk()
            ->assertExactJson(['page' => 1, 'perPage' => 10, 'total' => 0, 'orders' => []]);
    }

    #[Test]
    public function an_id_that_cannot_be_a_customer_has_no_list(): void
    {
        $this->getJson('/v1/customers/not-a-customer/orders')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function pagesOutOfBounds(): iterable
    {
        yield 'page zero' => ['page=0', ['page']];
        yield 'a page that is no number' => ['page=abc', ['page']];
        yield 'half a page' => ['page=1.5', ['page']];
        yield 'an empty page' => ['page=', ['page']];
        yield 'no orders per page' => ['perPage=0', ['perPage']];
        yield 'more orders per page than the list serves' => ['perPage=51', ['perPage']];
        yield 'both at once' => ['page=-1&perPage=100', ['page', 'perPage']];
    }

    /** @param list<string> $fields */
    #[Test]
    #[DataProvider('pagesOutOfBounds')]
    public function a_page_out_of_bounds_is_refused_field_by_field(string $query, array $fields): void
    {
        $this->getJson(sprintf('/v1/customers/%s/orders?%s', self::ANA, $query))
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonStructure(['errors' => $fields]);
    }

    #[Test]
    public function the_list_out_of_reach_is_a_503_that_says_when_to_come_back(): void
    {
        $logger = new RecordingLogger();
        $this->app->instance(LoggerInterface::class, $logger);
        $this->app->instance(ForReadingOrderViews::class, new class implements ForReadingOrderViews {
            public function page(CustomerId $customer, Page $page): CustomerOrders
            {
                throw OrderListUnavailable::forSeconds(5, new ConnectionTimeoutException('No suitable servers found'));
            }
        });

        $this->getJson(sprintf('/v1/customers/%s/orders', self::ANA))
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '5')
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'The order list is out of reach; try again in 5 s.');
        self::assertSame(['Order list out of reach: {cause}'], $logger->messagesAt('warning'));
    }

    #[Test]
    public function the_list_gives_up_at_once_when_mongodb_refuses_the_connection(): void
    {
        // Nothing listens on port 9 of the test container: the connection is refused on the spot.
        config(['read_models.uri' => 'mongodb://127.0.0.1:9/?directConnection=true']);
        $this->app->forgetInstance(Database::class);
        $views = $this->app->make(MongoOrderViews::class);

        $started = microtime(true);
        try {
            $views->page(CustomerId::fromString(self::ANA), Page::of(1));
            self::fail('A list came back with MongoDB out of reach.');
        } catch (OrderListUnavailable $unavailable) {
            self::assertSame(5, $unavailable->retryAfterSeconds);
        }
        self::assertLessThan(2.5, microtime(true) - $started, 'one short try, never the 30 s the driver would wait by default');
    }

    private function projected(Order $order): Order
    {
        $this->project($order);

        return $order;
    }

    /** What the relay would publish from the order, through the projector of the customer's list. */
    private function project(Order $order): void
    {
        $projector = $this->app->make(OrderViewProjector::class);
        foreach ($order->releaseEvents() as $event) {
            $projector->handle(OrderEvents::message(OrderEvents::of($event)));
        }
    }

    /** @return array<mixed> the problem without what changes from one request to another */
    private static function problemOf(mixed $problem): array
    {
        self::assertIsArray($problem);
        unset($problem['instance'], $problem['correlationId']);

        return $problem;
    }
}
