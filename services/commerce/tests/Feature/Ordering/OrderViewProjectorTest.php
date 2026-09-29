<?php

declare(strict_types=1);

namespace Tests\Feature\Ordering;

use Commerce\Ordering\Adapter\Driven\MongoOrderViews;
use Commerce\Ordering\Adapter\Driving\Kafka\OrderViewProjector;
use Commerce\Ordering\Application\OrderSummary;
use Commerce\Ordering\Application\Page;
use Commerce\Ordering\Application\UseCase\ProjectOrderView;
use Commerce\Ordering\Domain\Customer\CustomerId;
use Commerce\Ordering\Domain\Order\CancellationReason;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Ordering\Domain\Order\TrackingCode;
use DateTimeImmutable;
use Illuminate\Support\Facades\Artisan;
use MongoDB\BSON\Binary;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\AssertsContracts;
use Tests\Builders\OrderBuilder;
use Tests\Builders\OrderEvents;
use Tests\Doubles\RecordingLogger;
use Tests\TestCase;
use Tucano\Messaging\Kafka\PermanentFailure;

/** UC-ORD-08 from the record on commerce.orders.v2 to the document in order_views, with the collection validator in force. */
#[Group('integration')]
final class OrderViewProjectorTest extends TestCase
{
    use AssertsContracts;

    private const string ANA = '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d';

    private Database $database;

    private MongoOrderViews $views;

    private RecordingLogger $logger;

    private OrderViewProjector $projector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = $this->app->make(Database::class);
        $this->database->drop();
        self::assertSame(0, Artisan::call('mongo:migrate'));
        $this->views = new MongoOrderViews($this->database);
        $this->logger = new RecordingLogger();
        $this->projector = new OrderViewProjector(new ProjectOrderView($this->views), $this->logger);
    }

    #[Test]
    public function order_placed_opens_the_view_with_what_the_list_shows(): void
    {
        $order = self::anaOrders()
            ->withLines(OrderBuilder::line('BOOK-DDD-001', 'Domain-Driven Design', 2, 18990), OrderBuilder::line('HOME-MUG-001', 'Caneca de cerâmica', 1, 4990))
            ->placedAt('2026-09-27T12:00:00.123Z')
            ->place();

        $this->handle(OrderEvents::next($order));

        self::assertSame([
            'orderId' => $order->id()->toString(),
            'orderNumber' => (string) $order->toSnapshot()->number,
            'status' => 'pending_payment',
            'cancellationReason' => null,
            'total' => ['amount' => 42970, 'currency' => 'BRL'],
            'lines' => [
                ['sku' => 'BOOK-DDD-001', 'name' => 'Domain-Driven Design', 'quantity' => 2],
                ['sku' => 'HOME-MUG-001', 'name' => 'Caneca de cerâmica', 'quantity' => 1],
            ],
            'placedAt' => '2026-09-27T12:00:00.123+00:00',
            'updatedAt' => '2026-09-27T12:00:00.123+00:00',
        ], $this->viewOf($order)->toArray());
        self::assertSame(1, $this->versionOf($order));
        self::assertNull($this->documentOf($order)['shipment']);
    }

    #[Test]
    public function every_later_event_moves_the_view_on(): void
    {
        $order = self::anaOrders()->placedAt('2026-09-27T12:00:00Z')->place();
        $this->handle(OrderEvents::next($order));

        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $this->handle(OrderEvents::next($order));
        self::assertSame(['paid', '2026-09-27T12:05:00.000+00:00'], $this->statusOf($order));

        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $this->handle(OrderEvents::next($order));
        self::assertSame(['shipped', '2026-09-27T13:00:00.000+00:00'], $this->statusOf($order));

        $order->markAsDelivered(new DateTimeImmutable('2026-09-27T15:00:00Z'));
        $this->handle(OrderEvents::next($order));
        self::assertSame(['delivered', '2026-09-27T15:00:00.000+00:00'], $this->statusOf($order));
        self::assertSame(4, $this->versionOf($order));
        self::assertSame('2026-09-27T12:00:00.000+00:00', $this->viewOf($order)->toArray()['placedAt']);
    }

    #[Test]
    public function a_returned_order_ends_returned(): void
    {
        $order = self::anaOrders()->placedAt('2026-09-27T12:00:00Z')->place();
        $this->handle(OrderEvents::next($order));
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $this->handle(OrderEvents::next($order));
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $this->handle(OrderEvents::next($order));

        $order->markAsReturned(new DateTimeImmutable('2026-09-29T09:00:00Z'));
        $this->handle(OrderEvents::next($order));

        self::assertSame(['returned', '2026-09-29T09:00:00.000+00:00'], $this->statusOf($order));
    }

    #[Test]
    public function a_cancellation_says_why_from_either_way_in(): void
    {
        $declined = self::anaOrders()->placedAt('2026-09-27T12:00:00Z')->place();
        $this->handle(OrderEvents::next($declined));
        $declined->cancel(CancellationReason::PaymentDeclined, new DateTimeImmutable('2026-09-27T12:06:00Z'));
        $this->handle(OrderEvents::next($declined));

        $regretted = self::anaOrders()->placedAt('2026-09-27T13:00:00Z')->place();
        $this->handle(OrderEvents::next($regretted));
        $regretted->markAsPaid(new DateTimeImmutable('2026-09-27T13:05:00Z'));
        $this->handle(OrderEvents::next($regretted));
        $regretted->cancel(CancellationReason::CustomerRequest, new DateTimeImmutable('2026-09-27T13:30:00Z'));
        $this->handle(OrderEvents::next($regretted));

        self::assertSame(['cancelled', 'payment_declined'], [$this->viewOf($declined)->status->value, $this->viewOf($declined)->cancellationReason?->value]);
        self::assertSame(2, $this->versionOf($declined));
        self::assertSame(['cancelled', 'customer_request'], [$this->viewOf($regretted)->status->value, $this->viewOf($regretted)->cancellationReason?->value]);
        self::assertSame(3, $this->versionOf($regretted));
    }

    #[Test]
    public function an_old_event_replayed_after_a_newer_one_changes_nothing(): void
    {
        $order = self::anaOrders()->placedAt('2026-09-27T12:00:00Z')->place();
        $placed = OrderEvents::next($order);
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $paid = OrderEvents::next($order);
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $shipped = OrderEvents::next($order);
        foreach ([$placed, $paid, $shipped] as $event) {
            $this->handle($event);
        }

        $this->handle($paid);
        $this->handle($placed);

        self::assertSame(['shipped', '2026-09-27T13:00:00.000+00:00'], $this->statusOf($order));
        self::assertSame(['applied', 'applied', 'applied', 'duplicate', 'duplicate'], $this->outcomes());
    }

    #[Test]
    public function an_event_that_comes_late_does_not_take_the_view_back(): void
    {
        $order = self::anaOrders()->placedAt('2026-09-27T12:00:00Z')->place();
        $this->handle(OrderEvents::next($order));
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $paid = OrderEvents::next($order);
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));

        // The shipment is news before the payment, as after a replay of the dead letters.
        $this->handle(OrderEvents::next($order));
        $this->handle($paid);

        self::assertSame(['shipped', '2026-09-27T13:00:00.000+00:00'], $this->statusOf($order));
    }

    #[Test]
    public function the_same_event_twice_is_one_change(): void
    {
        $order = self::anaOrders()->placedAt('2026-09-27T12:00:00Z')->place();
        $placed = OrderEvents::next($order);
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $paid = OrderEvents::next($order);

        foreach ([$placed, $placed, $paid, $paid] as $event) {
            $this->handle($event);
        }

        self::assertSame(1, $this->database->selectCollection('order_views')->countDocuments());
        self::assertSame(['applied', 'duplicate', 'applied', 'duplicate'], $this->outcomes());
        self::assertSame(2, $this->versionOf($order));
    }

    #[Test]
    public function the_topic_read_again_from_the_start_rebuilds_the_same_list(): void
    {
        $events = [];
        foreach (['2026-09-27T12:00:00Z', '2026-09-27T13:00:00Z'] as $placedAt) {
            $order = self::anaOrders()->placedAt($placedAt)->place();
            $events[] = OrderEvents::next($order);
            $order->markAsPaid(new DateTimeImmutable($placedAt)->modify('+5 minutes'));
            $events[] = OrderEvents::next($order);
        }
        $this->handleAll($events);
        $built = $this->documents();

        $this->handleAll($events);
        self::assertSame($built, $this->documents(), 'a group that reads the topic again over the list changes nothing');

        $this->database->drop();
        self::assertSame(0, Artisan::call('mongo:migrate'));
        $this->handleAll($events);
        self::assertSame($built, $this->documents(), 'a new group over an empty collection builds the same list');
    }

    #[Test]
    public function an_order_placed_before_the_event_carried_names_shows_its_skus(): void
    {
        $order = self::anaOrders()->withLines(OrderBuilder::line('BOOK-DDD-001', 'Domain-Driven Design', 1, 18990))->place();

        $this->handle(OrderEvents::withoutNames(OrderEvents::next($order)));

        self::assertSame([['sku' => 'BOOK-DDD-001', 'name' => 'BOOK-DDD-001', 'quantity' => 1]], $this->viewOf($order)->toArray()['lines']);
    }

    #[Test]
    public function a_move_with_no_view_to_move_changes_nothing_and_says_so(): void
    {
        $order = self::anaOrders()->place();
        $order->releaseEvents();
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));

        $this->handle(OrderEvents::next($order));

        self::assertSame(0, $this->database->selectCollection('order_views')->countDocuments());
        self::assertCount(1, $this->logger->messagesAt('warning'));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadable(): iterable
    {
        $order = OrderBuilder::anOrder()->placedAt('2026-09-27T12:00:00Z')->place();
        $placed = OrderEvents::next($order);
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $paid = OrderEvents::next($order);
        $order->cancel(CancellationReason::CustomerRequest, new DateTimeImmutable('2026-09-27T12:10:00Z'));
        $cancelled = OrderEvents::next($order);

        yield 'an order id that is not a UUID' => [OrderEvents::changed($paid, ['orderId' => 'order-1'])];
        yield 'a customer id that is not a UUIDv7' => [OrderEvents::changed($placed, ['customerId' => '5f0c7a8e-1b2c-4d3e-8f4a-5b6c7d8e9f00'])];
        yield 'an order with no lines' => [OrderEvents::changed($placed, ['lines' => []])];
        yield 'an order number that is no number' => [OrderEvents::changed($placed, ['orderNumber' => 'A-17'])];
        yield 'a cancellation from a status that cannot be cancelled' => [OrderEvents::changed($cancelled, ['previousStatus' => 'shipped'])];
        yield 'a cancellation for no known reason' => [OrderEvents::changed($cancelled, ['reason' => 'bored'])];
    }

    #[Test]
    #[DataProvider('unreadable')]
    public function an_event_the_list_cannot_read_goes_to_the_dead_letters(string $payload): void
    {
        $this->expectException(PermanentFailure::class);

        $this->handle($payload);
    }

    #[Test]
    public function an_event_of_another_kind_is_left_alone(): void
    {
        $order = self::anaOrders()->place();

        $this->handle(str_replace('tucano.commerce.order.placed', 'tucano.commerce.order.teleported', OrderEvents::next($order)));

        self::assertSame(0, $this->database->selectCollection('order_views')->countDocuments());
    }

    #[Test]
    public function the_fixtures_speak_the_published_language_of_commerce(): void
    {
        $order = self::anaOrders()->place();
        $placed = OrderEvents::next($order);
        $order->markAsPaid(new DateTimeImmutable('2026-09-27T12:05:00Z'));
        $paid = OrderEvents::next($order);
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $shipped = OrderEvents::next($order);
        $order->markAsDelivered(new DateTimeImmutable('2026-09-27T15:00:00Z'));
        $delivered = OrderEvents::next($order);
        $returned = self::returned();
        $cancelled = self::cancelled();

        foreach ([$placed, OrderEvents::withoutNames($placed), $paid, $shipped, $delivered, $returned, $cancelled] as $payload) {
            $event = json_decode($payload, flags: JSON_THROW_ON_ERROR);
            self::assertInstanceOf(stdClass::class, $event);
            self::assertMatchesContract('cloudevent.schema.json', $event);
            self::assertMatchesContract(substr($event->type, strlen('tucano.')) . '.schema.json', $event->data);
        }
    }

    #[Test]
    public function a_name_is_optional_but_never_blank_nor_longer_than_the_product_name(): void
    {
        /** @var array{data: array{lines: list<array<string, mixed>>}} $placed */
        $placed = json_decode(OrderEvents::next(self::anaOrders()->place()), true, flags: JSON_THROW_ON_ERROR);
        $data = $placed['data'];

        $data['lines'][0]['name'] = '';
        self::assertBreaksContract('commerce.order.placed.schema.json', self::asJson($data));
        $data['lines'][0]['name'] = str_repeat('a', 161);
        self::assertBreaksContract('commerce.order.placed.schema.json', self::asJson($data));
        $data['lines'][0]['name'] = str_repeat('a', 160);
        self::assertMatchesContract('commerce.order.placed.schema.json', self::asJson($data));
    }

    private static function anaOrders(): OrderBuilder
    {
        return OrderBuilder::anOrder()->by(CustomerId::fromString(self::ANA));
    }

    private static function returned(): string
    {
        $order = OrderBuilder::anOrder()->paid();
        $order->markAsShipped(TrackingCode::of('TX02PWW6JFR5G00'), new DateTimeImmutable('2026-09-27T13:00:00Z'));
        $order->releaseEvents();
        $order->markAsReturned(new DateTimeImmutable('2026-09-29T09:00:00Z'));

        return OrderEvents::next($order);
    }

    private static function cancelled(): string
    {
        $order = OrderBuilder::anOrder()->paid();
        $order->cancel(CancellationReason::CustomerRequest, new DateTimeImmutable('2026-09-27T12:30:00Z'));

        return OrderEvents::next($order);
    }

    private function handle(string $payload): void
    {
        $this->projector->handle(OrderEvents::message($payload));
    }

    /** @param list<string> $payloads */
    private function handleAll(array $payloads): void
    {
        foreach ($payloads as $payload) {
            $this->handle($payload);
        }
    }

    private function viewOf(Order $order): OrderSummary
    {
        foreach ($this->views->page(CustomerId::fromString(self::ANA), Page::of(1, Page::MAX_SIZE))->orders as $view) {
            if ($view->orderId->equals($order->id())) {
                return $view;
            }
        }
        self::fail(sprintf('The list has no view of order %s.', $order->id()->toString()));
    }

    /** @return array{string, string} the status of the view and when it got there */
    private function statusOf(Order $order): array
    {
        $view = $this->viewOf($order)->toArray();

        return [$view['status'], $view['updatedAt']];
    }

    /** The collection validator already refuses a version that is not a 64-bit integer. */
    private function versionOf(Order $order): int
    {
        return (int) (string) $this->documentOf($order)['version'];
    }

    private function documentOf(Order $order): BSONDocument
    {
        $document = $this->database->selectCollection('order_views')->findOne(['_id' => new Binary($order->id()->toBytes(), Binary::TYPE_UUID)]);
        self::assertInstanceOf(BSONDocument::class, $document);

        return $document;
    }

    /** The whole collection, in the order of the ids, to compare two builds of it. */
    private function documents(): string
    {
        return json_encode($this->database->selectCollection('order_views')->find([], [
            'sort' => ['_id' => 1],
            'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array'],
        ])->toArray(), JSON_THROW_ON_ERROR);
    }

    /** @return list<mixed> what the projector did with each event, in order */
    private function outcomes(): array
    {
        return array_column($this->logger->contextsOf('Order view of {orderId}: {status} {outcome}'), 'outcome');
    }
}
