<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Database\Seeders\CarrierSeeder;
use Database\Seeders\FulfillmentCenterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tests\AssertsContracts;
use Tests\Builders\ShipmentBuilder;
use Tests\TestCase;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;
use UnexpectedValueException;

/** UC-SHP-04 to 08 over HTTP and PostgreSQL: CarrierFake's events, signed, into the state machine. */
#[Group('integration')]
final class CarrierWebhookTest extends TestCase
{
    use AssertsContracts;
    use RefreshDatabase;

    private const string SECRET = 'whsec_local_carriers';

    private FrozenClock $clock;

    private string $shipmentId;

    private string $trackingCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new FrozenClock('2026-09-27T15:00:00Z');
        $this->app->instance(Clock::class, $this->clock);
        $this->seed([FulfillmentCenterSeeder::class, CarrierSeeder::class]);
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::ReadyForPickup);
        $this->app->make(ForStoringShipments::class)->add($shipment);
        $reference = $shipment->toSnapshot()->reference;
        $this->shipmentId = $reference->id->toString();
        $this->trackingCode = (string) $reference->trackingCode;
    }

    #[Test]
    public function a_partner_journey_from_the_pickup_to_the_door(): void
    {
        $this->event('parcel.picked_up')->assertOk()->assertJsonPath('result', 'applied');
        $this->event('parcel.hub_scanned', ['hub' => 'Hub Cajamar (SP)'])->assertOk();
        $this->event('parcel.hub_scanned', ['hub' => 'Hub Contagem (MG)'])->assertOk();
        $this->event('parcel.out_for_delivery', ['attempt' => 1])->assertOk();
        $this->event('parcel.delivered', ['attempt' => 1, 'receiverName' => 'Carlos Lima', 'receiverDocument' => '***.456.789-**'])->assertOk();

        self::assertSame('delivered', $this->shipmentStatus());
        self::assertSame(
            ['Hub Cajamar (SP)', 'Hub Contagem (MG)'],
            DB::table('shipment_transitions')->where(['shipment_id' => $this->shipmentId, 'to_status' => 'in_transit'])->orderBy('occurred_at')->pluck('location')->all(),
        );
        self::assertEquals(
            [(object) ['attempt_number' => 1, 'outcome' => 'delivered', 'reason' => null, 'receiver_name' => 'Carlos Lima', 'receiver_document' => '***.456.789-**']],
            DB::table('delivery_attempts')->where('shipment_id', $this->shipmentId)->get(['attempt_number', 'outcome', 'reason', 'receiver_name', 'receiver_document'])->all(),
        );
        self::assertSame(
            ['picked_up', 'in_transit', 'in_transit', 'out_for_delivery', 'delivered'],
            array_map(static fn(stdClass $event): string => substr($event->type, strlen('tucano.logistics.shipment.')), $this->published()),
        );
        foreach ($this->published() as $event) {
            self::assertMatchesContract('cloudevent.schema.json', $event);
            self::assertMatchesContract(substr($event->type, strlen('tucano.')) . '.schema.json', $event->data);
            self::assertObjectNotHasProperty('receiverName', $event->data, 'Who received the parcels stays out of the topic.');
        }
    }

    #[Test]
    public function the_same_event_twice_is_applied_once(): void
    {
        $eventId = self::eventId();

        $this->event('parcel.picked_up', eventId: $eventId)->assertJsonPath('result', 'applied');
        $this->event('parcel.picked_up', eventId: $eventId)->assertOk()->assertJsonPath('result', 'duplicate');

        self::assertCount(1, $this->published());
    }

    #[Test]
    public function an_event_before_its_turn_is_refused_and_lands_when_it_comes_again(): void
    {
        // The pickup event was dropped, or is still on its way: the courier's news arrives first.
        $early = self::eventId();

        $this->event('parcel.out_for_delivery', ['attempt' => 1], $early)->assertStatus(409);
        self::assertFalse(DB::table('inbox_messages')->where('message_id', $early)->exists(), 'A refused step leaves no inbox mark behind.');

        $this->event('parcel.picked_up')->assertOk();
        $this->event('parcel.out_for_delivery', ['attempt' => 1], $early)->assertOk()->assertJsonPath('result', 'applied');
        self::assertSame('out_for_delivery', $this->shipmentStatus());
    }

    #[Test]
    public function three_failed_visits_send_the_parcels_back(): void
    {
        $this->event('parcel.picked_up');
        foreach ([1, 2, 3] as $visit) {
            $this->event('parcel.out_for_delivery', ['attempt' => $visit])->assertOk();
            $this->event('parcel.delivery_failed', ['attempt' => $visit, 'reason' => $visit === 2 ? 'address_not_found' : 'recipient_absent'])->assertOk();
        }
        $this->event('parcel.returning')->assertOk();
        $this->event('parcel.returned')->assertOk();

        self::assertSame('returned', $this->shipmentStatus());
        self::assertEquals(
            [(object) ['attempt_number' => 1, 'outcome' => 'failed', 'reason' => 'recipient_absent'], (object) ['attempt_number' => 2, 'outcome' => 'failed', 'reason' => 'address_not_found'], (object) ['attempt_number' => 3, 'outcome' => 'failed', 'reason' => 'recipient_absent']],
            DB::table('delivery_attempts')->where('shipment_id', $this->shipmentId)->orderBy('attempt_number')->get(['attempt_number', 'outcome', 'reason'])->all(),
        );
    }

    #[Test]
    public function a_refusal_sends_the_parcels_back_at_once_and_a_fourth_visit_never_happens(): void
    {
        $this->event('parcel.picked_up');
        $this->event('parcel.out_for_delivery', ['attempt' => 1]);
        $this->event('parcel.delivery_failed', ['attempt' => 1, 'reason' => 'recipient_refused'])->assertOk();

        $this->event('parcel.returning')->assertOk();

        self::assertSame('returning', $this->shipmentStatus());
        self::assertSame('refused', DB::table('delivery_attempts')->where('shipment_id', $this->shipmentId)->value('outcome'));
    }

    #[Test]
    public function what_is_not_a_signed_carrier_event_of_ours_changes_nothing(): void
    {
        $this->event('parcel.picked_up', secret: 'whsec_someone_else')->assertBadRequest()->assertJsonPath('detail', 'The Carrier-Signature is mismatch.');
        $this->event('parcel.hub_scanned')->assertBadRequest();
        $this->event('parcel.lost_in_space')->assertOk()->assertJsonPath('result', 'ignored');
        $this->event('parcel.picked_up', trackingCode: 'TX02PRZZZZZZZZZ')->assertOk()->assertJsonPath('result', 'unknown_shipment');

        self::assertSame('ready_for_pickup', $this->shipmentStatus());
    }

    /**
     * @param array<string, int|string> $details
     *
     * @return TestResponse<\Illuminate\Http\JsonResponse>
     */
    private function event(string $type, array $details = [], ?string $eventId = null, ?string $trackingCode = null, string $secret = self::SECRET): TestResponse
    {
        $this->clock->moveTo($this->clock->now()->modify('+1 second'));
        $body = json_encode([
            'id' => $eventId ?? self::eventId(),
            'type' => $type,
            'createdAt' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            'data' => [
                'pickupId' => 'pk_01M3HBNT8TE1R8SV13STYN9CXP',
                'carrier' => 'correio-nacional',
                'reference' => $this->shipmentId,
                'trackingCode' => $trackingCode ?? $this->trackingCode,
                ...$details,
            ],
        ], JSON_THROW_ON_ERROR);
        $signedAt = $this->clock->now()->getTimestamp();
        $signature = sprintf('t=%d,v1=%s', $signedAt, hash_hmac('sha256', $signedAt . '.' . $body, $secret));

        // call() sends the body byte for byte; the signature header goes as a server variable.
        return $this->call('POST', '/v1/webhooks/carriers', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_CARRIER_SIGNATURE' => $signature,
        ], $body);
    }

    private static function eventId(): string
    {
        return 'evt_' . strtoupper(substr(str_replace('-', '', Uuid::uuid7()->toString()), 0, 26));
    }

    private function shipmentStatus(): string
    {
        return (string) DB::table('shipments')->where('id', $this->shipmentId)->value('status');
    }

    /** @return list<stdClass> the CloudEvents in the outbox, oldest first */
    private function published(): array
    {
        return array_values(DB::table('outbox_messages')->orderBy('occurred_at')->orderBy('id')->pluck('payload')->map(static function (mixed $payload): stdClass {
            $event = json_decode((string) $payload, flags: JSON_THROW_ON_ERROR);

            return $event instanceof stdClass ? $event : throw new UnexpectedValueException('The outbox holds something that is not a CloudEvent.');
        })->all());
    }
}
