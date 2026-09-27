<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Logistics\Shipping\Adapter\Driving\Kafka\LabelRequestHandler;
use Logistics\Shipping\Application\UseCase\RequestLabel;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Tests\Doubles\Shipping\RecordedLabelQueue;
use Tests\TestCase;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;

final class LabelRequestHandlerTest extends TestCase
{
    private RecordedLabelQueue $queue;

    private LabelRequestHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();
        $this->queue = new RecordedLabelQueue();
        $this->handler = new LabelRequestHandler(new RequestLabel($this->queue), new NullLogger());
    }

    #[Test]
    public function every_created_shipment_asks_for_its_label(): void
    {
        $shipmentId = Uuid::uuid7()->toString();

        $this->handler->handle(self::message('tucano.logistics.shipment.created', $shipmentId));

        self::assertSame([$shipmentId], $this->queue->queued);
    }

    #[Test]
    public function the_other_shipment_events_are_not_for_the_label_queue(): void
    {
        $this->handler->handle(self::message('tucano.logistics.shipment.cancelled', Uuid::uuid7()->toString()));

        self::assertSame([], $this->queue->queued);
    }

    #[Test]
    public function a_created_event_without_a_readable_shipment_id_goes_to_the_dead_letter_topic(): void
    {
        $this->expectException(PermanentFailure::class);

        $this->handler->handle(self::message('tucano.logistics.shipment.created', 'not-a-uuid'));
    }

    private static function message(string $type, string $shipmentId): ReceivedMessage
    {
        $event = [
            'specversion' => '1.0',
            'id' => Uuid::uuid7()->toString(),
            'source' => '/logistics',
            'type' => $type,
            'subject' => $shipmentId,
            'time' => '2026-09-27T12:10:00.000Z',
            'datacontenttype' => 'application/json',
            'correlationid' => 'req-42#3',
            'data' => ['shipmentId' => $shipmentId, 'trackingCode' => 'TX02PRCV4T05G00', 'orderId' => Uuid::uuid7()->toString()],
        ];

        return new ReceivedMessage('logistics.shipments.v1', 0, 12, $shipmentId, (string) json_encode($event));
    }
}
