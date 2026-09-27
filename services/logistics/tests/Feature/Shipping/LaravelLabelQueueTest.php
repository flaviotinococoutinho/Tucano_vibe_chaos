<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Aws\Command;
use Aws\Sqs\Exception\SqsException;
use Illuminate\Contracts\Queue\Factory as Queues;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Support\Facades\Queue;
use Logistics\Shipping\Adapter\Driven\LaravelLabelQueue;
use Logistics\Shipping\Adapter\Driving\Queue\GenerateLabelJob;
use Logistics\Shipping\Domain\Error\LabelNotQueued;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class LaravelLabelQueueTest extends TestCase
{
    #[Test]
    public function the_request_goes_to_the_label_queue_with_the_shipment_id_only(): void
    {
        Queue::fake();
        $shipment = ShipmentId::generate();

        new LaravelLabelQueue($this->app->make(Queues::class), 'sqs', 'label-jobs')->queue($shipment);

        Queue::assertPushedOn('label-jobs', GenerateLabelJob::class, static fn(GenerateLabelJob $job): bool => $job->shipmentId === $shipment->toString());
    }

    #[Test]
    public function a_queue_that_does_not_answer_is_a_label_not_queued(): void
    {
        $queue = $this->createStub(QueueContract::class);
        $queue->method('pushOn')->willThrowException(new SqsException('Connection refused', new Command('SendMessage')));
        $queues = $this->createStub(Queues::class);
        $queues->method('connection')->willReturn($queue);

        $this->expectException(LabelNotQueued::class);

        new LaravelLabelQueue($queues, 'sqs', 'label-jobs')->queue(ShipmentId::generate());
    }
}
