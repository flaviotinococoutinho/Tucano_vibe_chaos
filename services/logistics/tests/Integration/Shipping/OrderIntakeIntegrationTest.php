<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Logistics\Shipping\Adapter\Driving\Kafka\OrderEventHandler;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Domain\Error\ProductNotSyncedYet;
use Logistics\Shipping\Domain\Shipment\OrderId;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\AssertsContracts;
use Tests\Builders\ShipmentBuilder;
use Tests\Fixtures\OrderEvents;
use Tests\TestCase;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

/** The order intake end to end: event in, rows and outbox out, through the real use cases and PostgreSQL. */
#[Group('integration')]
final class OrderIntakeIntegrationTest extends TestCase
{
    use AssertsContracts;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(Clock::class, new FrozenClock('2026-09-27T12:10:00Z'));
        $this->app->instance(FeatureFlags::class, new InMemoryFlags(['logistics.own-fleet-dispatch' => true]));
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
        DB::table('product_snapshots')->insert([
            self::snapshot('BOOK-DDD-001', 1100, 240, 170, 40),
            self::snapshot('HOME-MUG-001', 350, 120, 90, 100),
        ]);
    }

    #[Test]
    public function a_paid_order_becomes_a_shipment_with_its_parcels_and_history(): void
    {
        $this->handle(OrderEvents::paid());

        $shipment = DB::table('shipments')->sole();
        self::assertSame(
            ['created', 'tucano-express', 'GRU1', 'Ana Souza', 'SP', '01310100', 2550, 0, 1],
            [$shipment->status, $shipment->carrier_code, $shipment->origin, $shipment->recipient_name, $shipment->dest_state, $shipment->dest_postal_code, $shipment->total_weight_grams, $shipment->delivery_attempts, $shipment->version],
        );
        self::assertEquals(new NodeId(1, 11), Snowflake::fromInt((int) $shipment->tracking_code)->node(), 'The tracking code comes from the Snowflake node configured for the process.');
        self::assertEquals(
            [(object) ['parcel_number' => 1, 'weight_grams' => 2200, 'height_mm' => 80], (object) ['parcel_number' => 2, 'weight_grams' => 350, 'height_mm' => 100]],
            DB::table('parcels')->where('shipment_id', $shipment->id)->orderBy('parcel_number')->get(['parcel_number', 'weight_grams', 'height_mm'])->all(),
        );
        self::assertEquals(
            [(object) ['from_status' => null, 'to_status' => 'created']],
            DB::table('shipment_transitions')->where('shipment_id', $shipment->id)->get(['from_status', 'to_status'])->all(),
        );
        self::assertTrue(DB::table('inbox_messages')->where(['consumer' => 'logistics.order-intake', 'message_id' => OrderEvents::PAID_EVENT])->exists());
    }

    #[Test]
    public function shipment_created_goes_to_the_outbox_in_the_shape_of_its_contract(): void
    {
        $this->handle(OrderEvents::paid());

        $shipmentId = (string) DB::table('shipments')->value('id');
        $message = DB::table('outbox_messages')->sole();
        self::assertSame(['logistics.shipments.v1', $shipmentId, 'tucano.logistics.shipment.created'], [$message->topic, $message->message_key, $message->event_type]);

        $event = self::decode((string) $message->payload);
        self::assertSame(['/logistics', 'req-42#3', OrderEvents::PAID_EVENT], [$event->source, $event->correlationid, $event->causationid]);
        self::assertMatchesContract('cloudevent.schema.json', $event);
        self::assertMatchesContract('logistics.shipment.created.schema.json', $event->data);
    }

    #[Test]
    public function the_same_event_twice_creates_one_shipment(): void
    {
        $this->handle(OrderEvents::paid());
        $this->handle(OrderEvents::paid());

        self::assertSame(1, DB::table('shipments')->count());
        self::assertSame(1, DB::table('outbox_messages')->count());
    }

    #[Test]
    public function a_product_missing_from_the_copy_leaves_nothing_behind_and_the_retry_works(): void
    {
        $mug = (array) DB::table('product_snapshots')->where('sku', 'HOME-MUG-001')->sole();
        DB::table('product_snapshots')->where('sku', 'HOME-MUG-001')->delete();

        try {
            $this->handle(OrderEvents::paid());
            self::fail('The event should wait for the catalog copy.');
        } catch (ProductNotSyncedYet) {
            self::assertSame([0, 0, 0], [DB::table('shipments')->count(), DB::table('inbox_messages')->count(), DB::table('outbox_messages')->count()]);
        }

        DB::table('product_snapshots')->insert($mug);
        $this->handle(OrderEvents::paid());

        self::assertSame(1, DB::table('shipments')->count());
    }

    #[Test]
    public function an_order_to_another_state_goes_with_the_smallest_partner(): void
    {
        $this->handle(OrderEvents::paid(['shippingAddress' => OrderEvents::plainAddress()]));

        self::assertSame('correio-nacional', DB::table('shipments')->value('carrier_code'));
    }

    #[Test]
    public function a_cancelled_order_stops_its_shipment_and_says_so_in_the_shape_of_its_contract(): void
    {
        $this->handle(OrderEvents::paid());

        $this->handle(OrderEvents::cancelled());

        $shipment = DB::table('shipments')->sole();
        self::assertSame(['cancelled', 2], [$shipment->status, $shipment->version]);
        self::assertEquals(
            [(object) ['from_status' => null, 'to_status' => 'created', 'reason' => null], (object) ['from_status' => 'created', 'to_status' => 'cancelled', 'reason' => 'order_cancelled']],
            DB::table('shipment_transitions')->where('shipment_id', $shipment->id)->orderBy('occurred_at')->get(['from_status', 'to_status', 'reason'])->all(),
        );
        $event = self::decode((string) DB::table('outbox_messages')->where('event_type', 'tucano.logistics.shipment.cancelled')->value('payload'));
        self::assertSame(OrderEvents::CANCELLED_EVENT, $event->causationid);
        self::assertMatchesContract('cloudevent.schema.json', $event);
        self::assertMatchesContract('logistics.shipment.cancelled.schema.json', $event->data);
    }

    #[Test]
    public function a_shipment_that_left_the_warehouse_is_left_alone_for_a_person(): void
    {
        $shipment = ShipmentBuilder::aShipment()->forOrder(OrderId::fromString(OrderEvents::ORDER))->in(ShipmentStatus::PickedUp);
        $this->app->make(ForStoringShipments::class)->add($shipment);

        try {
            $this->handle(OrderEvents::cancelled());
            self::fail('A shipment on its way cannot be cancelled.');
        } catch (PermanentFailure $failure) {
            self::assertStringContainsString('picked_up and cannot move to cancelled', $failure->getMessage());
        }

        self::assertSame('picked_up', DB::table('shipments')->value('status'));
        self::assertSame([0, 0], [DB::table('outbox_messages')->count(), DB::table('inbox_messages')->count()]);
    }

    #[Test]
    public function a_payment_replayed_after_the_cancellation_does_not_ship_the_order(): void
    {
        // order.paid went to the dead letter topic (the database was down), the order was cancelled
        // meanwhile, and someone replays the dead letter afterwards: the events arrive out of order.
        $this->handle(OrderEvents::cancelled());
        $this->handle(OrderEvents::paid());

        self::assertSame(0, DB::table('shipments')->count());
        self::assertSame(0, DB::table('outbox_messages')->count());
        self::assertTrue(DB::table('cancelled_orders')->where('order_id', OrderEvents::ORDER)->exists());
        self::assertSame(2, DB::table('inbox_messages')->count());
    }

    #[Test]
    public function an_order_cancelled_before_payment_touches_nothing(): void
    {
        $this->handle(OrderEvents::expired());

        self::assertSame([0, 0], [DB::table('inbox_messages')->count(), DB::table('outbox_messages')->count()]);
    }

    private function handle(string $payload): void
    {
        $this->app->make(OrderEventHandler::class)->handle(OrderEvents::message($payload));
    }

    private static function decode(string $payload): stdClass
    {
        $event = json_decode($payload, flags: JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $event);

        return $event;
    }

    /** @return array<string, int|string> */
    private static function snapshot(string $sku, int $grams, int $lengthMm, int $widthMm, int $heightMm): array
    {
        return [
            'product_id' => Uuid::uuid7()->toString(),
            'sku' => $sku,
            'name' => $sku,
            'weight_grams' => $grams,
            'length_mm' => $lengthMm,
            'width_mm' => $widthMm,
            'height_mm' => $heightMm,
            'catalog_version' => 1,
        ];
    }
}
