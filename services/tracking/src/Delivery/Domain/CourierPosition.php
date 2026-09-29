<?php

declare(strict_types=1);

namespace Tracking\Delivery\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Where the courier carrying a parcel is, and how far the door still is along the route. */
final readonly class CourierPosition implements DeliveryNews
{
    private function __construct(
        private TrackingCode $code,
        public float $latitude,
        public float $longitude,
        public DateTimeImmutable $at,
        public int $remainingMeters,
    ) {}

    public static function of(TrackingCode $code, float $latitude, float $longitude, DateTimeImmutable $at, int $remainingMeters): self
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new InvalidArgumentException('A latitude is between -90 and 90 degrees.');
        }
        if ($longitude < -180 || $longitude > 180) {
            throw new InvalidArgumentException('A longitude is between -180 and 180 degrees.');
        }
        if ($remainingMeters < 0) {
            throw new InvalidArgumentException('The distance left to the door is never negative.');
        }

        return new self($code, $latitude, $longitude, $at->setTimezone(new DateTimeZone('UTC')), $remainingMeters);
    }

    public function trackingCode(): TrackingCode
    {
        return $this->code;
    }

    public function toArray(): array
    {
        return [
            'type' => 'position',
            'trackingCode' => $this->code->value,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'at' => $this->at->format('Y-m-d\TH:i:s.v\Z'),
            'remainingMeters' => $this->remainingMeters,
        ];
    }
}
