<?php

declare(strict_types=1);

namespace Tests\Integration\Shipping;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Logistics\Shipping\Adapter\Driven\S3Labels;
use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/** The S3 adapter against Floci, the local AWS. It runs when FLOCI_ENDPOINT says where Floci is. */
#[Group('integration')]
final class S3LabelsIntegrationTest extends TestCase
{
    private const string BUCKET = 'tucano-labels';

    #[Test]
    public function a_label_stored_in_the_bucket_comes_back_as_it_was(): void
    {
        $s3 = self::s3();
        $labels = new S3Labels($s3, self::BUCKET, new InMemoryFlags(['chaos.logistics.label-failure-rate' => 0.0]), static fn(): float => 0.5);
        $trackingCode = new TrackingCode(Snowflake::compose((int) (microtime(true) * 1000), new NodeId(1, 12), 0));

        $label = $labels->store($trackingCode, new LabelDocument("^XA^CI28^FDSão Paulo^FS^XZ\n", 'zpl', 'text/plain; charset=utf-8'));
        $object = $s3->getObject(['Bucket' => self::BUCKET, 'Key' => $label->objectKey]);

        self::assertSame("^XA^CI28^FDSão Paulo^FS^XZ\n", (string) $object['Body']);
        self::assertSame('text/plain; charset=utf-8', $object['ContentType']);
    }

    private static function s3(): S3Client
    {
        $endpoint = (string) getenv('FLOCI_ENDPOINT');
        if ($endpoint === '') {
            self::markTestSkipped('Set FLOCI_ENDPOINT to run the S3 tests against Floci.');
        }
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'http' => ['timeout' => 5, 'connect_timeout' => 2],
        ]);
        try {
            // The stack creates the bucket on start; a bare Floci in the CI does not.
            $s3->createBucket(['Bucket' => self::BUCKET]);
        } catch (S3Exception $exists) {
            if (!in_array($exists->getAwsErrorCode(), ['BucketAlreadyOwnedByYou', 'BucketAlreadyExists'], true)) {
                throw $exists;
            }
        }

        return $s3;
    }
}
