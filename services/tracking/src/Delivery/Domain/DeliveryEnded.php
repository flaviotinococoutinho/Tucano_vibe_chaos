<?php

declare(strict_types=1);

namespace Tracking\Delivery\Domain;

use DateTimeImmutable;
use DateTimeZone;

/** The visit is over, delivered or not: no position follows, and the followers are let go. */
final readonly class DeliveryEnded implements DeliveryNews
{
    private function __construct(
        private TrackingCode $code,
        public VisitOutcome $outcome,
        public DateTimeImmutable $at,
    ) {}

    public static function of(TrackingCode $code, VisitOutcome $outcome, DateTimeImmutable $at): self
    {
        return new self($code, $outcome, $at->setTimezone(new DateTimeZone('UTC')));
    }

    public function trackingCode(): TrackingCode
    {
        return $this->code;
    }

    public function toArray(): array
    {
        return [
            'type' => 'ended',
            'trackingCode' => $this->code->value,
            'outcome' => $this->outcome->value,
            'at' => $this->at->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
