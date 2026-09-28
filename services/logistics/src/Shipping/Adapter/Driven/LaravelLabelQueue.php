<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Aws\Exception\AwsException;
use Illuminate\Contracts\Queue\Factory as Queues;
use Logistics\Shipping\Adapter\Driving\Queue\GenerateLabelJob;
use Logistics\Shipping\Adapter\Driving\Queue\LabelJobSettings;
use Logistics\Shipping\Application\Port\Driven\ForQueuingLabels;
use Logistics\Shipping\Domain\Error\LabelNotQueued;
use Logistics\Shipping\Domain\Shipment\ShipmentId;

/** The label-jobs queue on SQS, through the queue of Laravel: the job carries only the shipment id. */
final readonly class LaravelLabelQueue implements ForQueuingLabels
{
    public function __construct(private Queues $queues, private string $connection, private string $queue, private LabelJobSettings $settings) {}

    public function queue(ShipmentId $shipment): void
    {
        try {
            $this->queues->connection($this->connection)->pushOn($this->queue, new GenerateLabelJob($shipment->toString(), $this->settings));
        } catch (AwsException $failure) {
            throw LabelNotQueued::because($failure->getAwsErrorMessage() ?? $failure->getMessage(), $failure);
        }
    }
}
