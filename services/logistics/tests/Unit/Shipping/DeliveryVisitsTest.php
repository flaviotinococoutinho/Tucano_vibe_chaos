<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use DateTimeImmutable;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Shipment\AttemptOutcome;
use Logistics\Shipping\Domain\Shipment\ShipmentStatus;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\DeliveryFailure;
use Logistics\Shipping\Domain\Transition\ProofOfDelivery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Builders\ShipmentBuilder;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

final class DeliveryVisitsTest extends TestCase
{
    #[Test]
    public function every_visit_is_kept_with_its_proof_or_its_reason_and_handed_over_once(): void
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::OutForDelivery);
        $shipment->releaseVisits();
        $at = new DateTimeImmutable('2026-09-27T15:00:00Z');

        $shipment->recordFailedAttempt(DeliveryFailure::RecipientAbsent, $at);
        $shipment->sendOutForDelivery($at->modify('+1 hour'));
        $shipment->recordDelivery(ProofOfDelivery::of('Carlos Lima', '***.456.789-**'), $at->modify('+2 hours'));
        $visits = $shipment->releaseVisits();

        self::assertSame([[1, AttemptOutcome::Failed, DeliveryFailure::RecipientAbsent, null], [2, AttemptOutcome::Delivered, null, 'Carlos Lima']], array_map(
            static fn($visit): array => [$visit->number, $visit->outcome, $visit->failure, $visit->proof?->receiverName],
            $visits,
        ));
        self::assertSame([], $shipment->releaseVisits());
    }

    #[Test]
    public function a_refusal_is_its_own_outcome(): void
    {
        $shipment = ShipmentBuilder::aShipment()->in(ShipmentStatus::OutForDelivery);

        $shipment->recordFailedAttempt(DeliveryFailure::RecipientRefused, new DateTimeImmutable('2026-09-27T15:00:00Z'));

        self::assertSame(AttemptOutcome::Refused, $shipment->releaseVisits()[0]->outcome);
    }

    #[Test]
    public function a_tracking_code_reads_back_as_it_was_written(): void
    {
        $code = TrackingCode::fromSnowflake(Snowflake::compose(1_790_510_400_000, new NodeId(1, 12), 42));

        self::assertSame((string) $code, (string) TrackingCode::fromString((string) $code));
        $this->expectException(InvalidShipment::class);

        TrackingCode::fromString('TX02PRIL00000');
    }
}
