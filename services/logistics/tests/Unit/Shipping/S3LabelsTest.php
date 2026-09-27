<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Logistics\Shipping\Adapter\Driven\S3Labels;
use Logistics\Shipping\Application\LabelDocument;
use Logistics\Shipping\Domain\Error\LabelNotStored;
use Logistics\Shipping\Domain\Shipment\TrackingCode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\FeatureFlags\InMemoryFlags;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

final class S3LabelsTest extends TestCase
{
    /** @var list<CommandInterface> */
    private array $sent = [];

    #[Test]
    public function the_label_goes_to_the_bucket_under_its_tracking_code(): void
    {
        $trackingCode = self::trackingCode();

        $label = $this->labels(new Result([]))->store($trackingCode, self::document());

        self::assertSame("labels/{$trackingCode}.zpl", $label->objectKey);
        $put = $this->sent[0];
        self::assertSame(
            ['PutObject', 'tucano-labels', "labels/{$trackingCode}.zpl", '^XA^XZ', 'text/plain; charset=utf-8'],
            [$put->getName(), $put['Bucket'], $put['Key'], $put['Body'], $put['ContentType']],
        );
    }

    #[Test]
    public function a_bucket_that_fails_is_a_label_not_stored(): void
    {
        $this->expectException(LabelNotStored::class);

        $this->labels(static fn(CommandInterface $command) => new S3Exception('Slow Down', $command, ['code' => 'SlowDown']))
            ->store(self::trackingCode(), self::document());
    }

    #[Test]
    public function the_chaos_rate_fails_the_store_before_it_reaches_the_bucket(): void
    {
        try {
            $this->labels(new Result([]), failureRate: 0.2, roll: 0.19)->store(self::trackingCode(), self::document());
            self::fail('A roll below the failure rate should fail.');
        } catch (LabelNotStored $chaos) {
            self::assertStringContainsString('chaos', $chaos->getMessage());
        }
        self::assertSame([], $this->sent);

        $this->labels(new Result([]), failureRate: 0.2, roll: 0.2)->store(self::trackingCode(), self::document());
        self::assertCount(1, $this->sent);
    }

    /** @param Result<string, mixed>|callable $answer what the bucket answers to the one call it gets */
    private function labels(Result|callable $answer, float $failureRate = 0.0, float $roll = 0.5): S3Labels
    {
        $s3 = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'handler' => new MockHandler([$answer]),
        ]);
        $s3->getHandlerList()->appendInit(function (callable $next) {
            return function (CommandInterface $command, mixed $request = null) use ($next) {
                $this->sent[] = $command;

                return $next($command, $request);
            };
        }, 'record');

        return new S3Labels($s3, 'tucano-labels', new InMemoryFlags(['chaos.logistics.label-failure-rate' => $failureRate]), static fn(): float => $roll);
    }

    private static function trackingCode(): TrackingCode
    {
        return new TrackingCode(Snowflake::compose(1_790_510_400_000, new NodeId(1, 12), 7));
    }

    private static function document(): LabelDocument
    {
        return new LabelDocument('^XA^XZ', 'zpl', 'text/plain; charset=utf-8');
    }
}
