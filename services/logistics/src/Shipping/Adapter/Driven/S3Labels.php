<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driven;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Closure;
use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Application\Port\Driven\ForStoringLabels;
use Logistics\Shipping\Domain\Error\LabelNotStored;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use Logistics\Shipping\Domain\Transition\ShippingLabel;
use Tucano\FeatureFlags\FeatureFlags;

/**
 * Labels live in the tucano-labels bucket, one object per tracking code. The
 * chaos flag fails a share of the stores before they reach S3, the way a slow
 * or unavailable bucket would, so the retries of the label queue can be watched.
 */
final readonly class S3Labels implements ForStoringLabels
{
    private const string FAILURE_RATE_FLAG = 'chaos.logistics.label-failure-rate';

    /** @param Closure(): float $roll a number in [0, 1), compared with the failure rate */
    public function __construct(
        private S3Client $s3,
        private string $bucket,
        private FeatureFlags $flags,
        private Closure $roll,
    ) {}

    public function store(TrackingCode $trackingCode, LabelDocument $label): ShippingLabel
    {
        $failureRate = $this->flags->decimal(self::FAILURE_RATE_FLAG, 0.0);
        if ($failureRate > 0.0 && ($this->roll)() < $failureRate) {
            throw LabelNotStored::because(sprintf('chaos, with a failure rate of %s', $failureRate));
        }

        $key = sprintf('labels/%s.%s', $trackingCode, $label->extension);
        try {
            $this->s3->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $label->contents,
                'ContentType' => $label->contentType,
            ]);
        } catch (AwsException $failure) {
            throw LabelNotStored::because($failure->getAwsErrorMessage() ?? $failure->getMessage(), $failure);
        }

        return ShippingLabel::storedAt($key);
    }
}
