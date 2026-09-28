<?php

declare(strict_types=1);

namespace Tests\Doubles\Shipping;

use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Application\Port\Driven\ForStoringLabels;
use Logistics\Shipping\Domain\Error\LabelNotStored;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\ShippingLabel;

/** A bucket in memory, until the test makes it fail. */
final class InMemoryLabels implements ForStoringLabels
{
    /** @var array<string, LabelDocument> object key => label */
    public private(set) array $stored = [];

    private int $failuresLeft = 0;

    public function failNextTimes(int $times): void
    {
        $this->failuresLeft = $times;
    }

    public function store(TrackingCode $trackingCode, LabelDocument $label): ShippingLabel
    {
        if ($this->failuresLeft > 0) {
            $this->failuresLeft--;

            throw LabelNotStored::because('the bucket did not answer');
        }
        $key = sprintf('labels/%s.%s', $trackingCode, $label->extension);
        $this->stored[$key] = $label;

        return ShippingLabel::storedAt($key);
    }
}
