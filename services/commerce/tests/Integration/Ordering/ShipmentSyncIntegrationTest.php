<?php

declare(strict_types=1);

namespace Tests\Integration\Ordering;

use Commerce\Ordering\Adapter\Driving\Kafka\ShipmentEventHandler;
use Commerce\Ordering\Application\Port\Driven\ForStoringOrders;
use Commerce\Ordering\Domain\Order\Order;
use Commerce\Payments\Application\Port\Driven\ForStoringPayments;
use Commerce\Payments\Domain\Payment;
use Commerce\Payments\Domain\PaymentId;
use Database\Seeders\FulfillmentCenterSeeder;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Tests\AssertsContracts;
use Tests\Builders\OrderBuilder;
use Tests\Builders\ShipmentEvents;
use Tests\TestCase;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;
use UnexpectedValueException;

/** UC-ORD-04 against PostgreSQL: the order, its history, its outbox and, on a return, its payment. */
#[Group('integration')]
final class ShipmentSyncIntegrationTest extends TestCase
{
    use AssertsContracts;
    use RefreshDatabase;

    private ShipmentEventHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FulfillmentCenterSeeder::class);
        $this->handler = $this->app->make(ShipmentEventHandler::class);
    }

    #[Test]
    public function the_order_follows_its_shipment_to_the_door_once_per_event(): void
    {
        $order = $this->storedPaidOrder();
        $orderId = $order->id()->toString();
        $pickedUp = ShipmentEvents::of('picked_up', $orderId, '2026-09-27T13:00:00.000Z');

        $this->handle($pickedUp);
        $this->handle($pickedUp);
        $this->handle(ShipmentEvents::of('delivered', $orderId, '2026-09-27T15:00:00.000Z', details: ['attempt' => 1]));

        self::assertSame(['status' => 'delivered', 'tracking_code' => 'TX02PWW6JFR5G00'], (array) DB::table('orders')->where('id', $orderId)->first(['status', 'tracking_code']));
        self::assertSame(['shipped', 'delivered'], DB::table('order_status_transitions')->where('order_id', $orderId)->orderBy('occurred_at')->pluck('to_status')->all());
        $published = $this->published();
        self::assertSame(['tucano.commerce.order.shipped', 'tucano.commerce.order.delivered'], array_map(static fn(stdClass $event): string => $event->type, $published));
        foreach ($published as $event) {
            self::assertMatchesContract('cloudevent.schema.json', $event);
            self::assertMatchesContract(substr($event->type, strlen('tucano.')) . '.schema.json', $event->data);
            self::assertSame('arara', $event->data->store, 'every event of the order says its store');
        }
    }

    #[Test]
    public function a_returned_order_hands_its_payment_to_the_refund(): void
    {
        $order = $this->storedPaidOrder();
        $orderId = $order->id()->toString();
        $paymentId = $this->capturedPaymentOf($orderId);

        $this->handle(ShipmentEvents::of('picked_up', $orderId, '2026-09-27T13:00:00.000Z'));
        $this->handle(ShipmentEvents::of('returned', $orderId, '2026-09-27T19:00:00.000Z'));

        self::assertSame('returned', DB::table('orders')->where('id', $orderId)->value('status'));
        self::assertSame('refund_requested', DB::table('payments')->where('id', $paymentId)->value('status'));
        self::assertSame('tucano.commerce.order.returned', $this->published()[1]->type);
    }

    private function storedPaidOrder(): Order
    {
        $order = OrderBuilder::anOrder()->paid();
        $this->app->make(ForStoringOrders::class)->add($order);

        return $order;
    }

    private function capturedPaymentOf(string $orderId): string
    {
        $payments = $this->app->make(ForStoringPayments::class);
        $at = new DateTimeImmutable('2026-09-27T12:01:00Z');
        $payment = Payment::start(PaymentId::generate(), $orderId, Money::of(18990, Currency::brl()), $at);
        $payments->addUnlessPending($payment);
        $payment->chargedAs('ch_1', $at);
        $payment->capture($at);
        $payments->save($payment);

        return $payment->id->toString();
    }

    private function handle(string $payload): void
    {
        $this->handler->handle(new ReceivedMessage('logistics.shipments.v2', 0, 7, ShipmentEvents::SHIPMENT, $payload));
    }

    /** @return list<stdClass> the CloudEvents in the outbox, oldest first */
    private function published(): array
    {
        return array_values(DB::table('outbox_messages')->where('topic', 'commerce.orders.v2')->orderBy('occurred_at')->orderBy('id')->pluck('payload')->map(static function (mixed $payload): stdClass {
            $event = json_decode((string) $payload, flags: JSON_THROW_ON_ERROR);

            return $event instanceof stdClass ? $event : throw new UnexpectedValueException('The outbox holds something that is not a CloudEvent.');
        })->all());
    }
}
