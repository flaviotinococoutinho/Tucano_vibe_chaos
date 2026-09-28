<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driving\Kafka;

use Logistics\Timeline\Application\Port\Driving\ForUpdatingTrackingPages;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\ReceivedMessage;

/** Reads logistics.shipments.v2 for the consumer group logistics.tracking-pages: each step goes to the public page (UC-SHP-10). */
final readonly class TrackingPageProjector implements MessageHandler
{
    public function __construct(private ForUpdatingTrackingPages $pages, private LoggerInterface $logger) {}

    public function handle(ReceivedMessage $message): void
    {
        $news = ShipmentStepNews::of($message);
        if ($news === null) {
            return;
        }

        $outcome = $this->pages->update($news);
        $this->logger->debug('Tracking page of {trackingCode}: {status} {outcome}', ['trackingCode' => $news->trackingCode, 'status' => $news->step->status->value, 'outcome' => $outcome->value]);
    }
}
