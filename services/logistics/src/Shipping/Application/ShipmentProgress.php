<?php

declare(strict_types=1);

namespace Logistics\Shipping\Application;

use Closure;
use Logistics\Shared\Application\Port\Driven\ForDeduplicatingMessages;
use Logistics\Shared\Application\Port\Driven\ForPublishingEvents;
use Logistics\Shared\Application\Port\Driven\ForRunningTransactions;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Domain\Shipment\Shipment;

/**
 * What every step reported by a carrier does, UC-SHP-04 to 08: in one
 * transaction, the inbox mark, the shipment locked by its tracking code, the
 * step through the state machine, the history, the visits and the event in the
 * outbox. A step the machine refuses rolls everything back, the inbox mark
 * included, so the same event can be applied when it is retried in its turn.
 * An event the shipment already went past keeps its inbox mark and moves
 * nothing: it is handled, and it is not sent again.
 */
final readonly class ShipmentProgress
{
    private const string INBOX = 'logistics.carrier-events';

    public function __construct(
        private ForRunningTransactions $transactions,
        private ForDeduplicatingMessages $inbox,
        private ForStoringShipments $shipments,
        private ForPublishingEvents $events,
    ) {}

    /**
     * @param Closure(Shipment): void      $step
     * @param (Closure(Shipment): bool)|null $obsolete true when the shipment already went past the step
     */
    public function apply(CarrierReport $report, Closure $step, ?Closure $obsolete = null): ProgressOutcome
    {
        return $this->transactions->run(function () use ($report, $step, $obsolete): ProgressOutcome {
            if (!$this->inbox->firstTime(self::INBOX, $report->eventId)) {
                return ProgressOutcome::Duplicate;
            }
            $shipment = $this->shipments->withTrackingCode($report->trackingCode);
            if ($shipment === null) {
                return ProgressOutcome::UnknownShipment;
            }
            if ($obsolete !== null && $obsolete($shipment)) {
                return ProgressOutcome::Obsolete;
            }
            $step($shipment);
            $this->shipments->save($shipment);
            $this->events->publish(...$shipment->releaseEvents());

            return ProgressOutcome::Applied;
        });
    }
}
