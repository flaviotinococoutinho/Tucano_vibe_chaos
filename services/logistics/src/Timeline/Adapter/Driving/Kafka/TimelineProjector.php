<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Kafka;

use Logistics\Timeline\Application\Port\Driving\ForProjectingTimelines;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\ReceivedMessage;

/** Reads logistics.shipments.v2 for the consumer group logistics.timeline-projector: each step goes to the internal timeline (UC-SHP-10). */
final readonly class TimelineProjector implements MessageHandler
{
    public function __construct(private ForProjectingTimelines $timelines, private LoggerInterface $logger) {}

    public function handle(ReceivedMessage $message): void
    {
        $news = ShipmentStepNews::of($message);
        if ($news === null) {
            return;
        }

        $outcome = $this->timelines->project($news);
        $this->logger->debug('Timeline of {trackingCode}: {status} {outcome}', ['trackingCode' => $news->trackingCode, 'status' => $news->step->status->value, 'outcome' => $outcome->value]);
    }
}
