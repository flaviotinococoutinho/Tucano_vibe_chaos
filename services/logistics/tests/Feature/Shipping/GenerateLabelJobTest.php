<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Support\Facades\Log;
use Logistics\Shipping\Adapter\Driving\Queue\GenerateLabelJob;
use Logistics\Shipping\Adapter\Driving\Queue\LabelJobSettings;
use Logistics\Shipping\Application\LabelOutcome;
use Logistics\Shipping\Application\Port\Driving\ForGeneratingLabels;
use Logistics\Shipping\Domain\Error\InvalidShipment;
use Logistics\Shipping\Domain\Error\LabelNotStored;
use Logistics\Shipping\Domain\Shipment\ShipmentId;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\Doubles\RecordingLogger;
use Tests\TestCase;
use Throwable;

final class GenerateLabelJobTest extends TestCase
{
    #[Test]
    public function the_job_and_the_queue_agree_on_three_tries_within_the_visibility_timeout(): void
    {
        // infra/floci/ready.d makes label-jobs with a maxReceiveCount of 3 and a visibility timeout of 60 s.
        $defaults = [config('labels.job.tries'), config('labels.job.backoff_seconds'), config('labels.job.timeout_seconds')];
        $job = new GenerateLabelJob((string) ShipmentId::generate(), self::settings());

        self::assertSame([3, [5, 20], 30], $defaults);
        self::assertSame([3, [5, 20], 30], [$job->tries, $job->backoff, $job->timeout]);
    }

    #[Test]
    public function a_storage_failure_is_thrown_so_the_queue_tries_again_and_the_log_says_so(): void
    {
        $job = new GenerateLabelJob((string) ShipmentId::generate(), self::settings())->withFakeQueueInteractions();
        $logger = new RecordingLogger();

        try {
            $job->handle(self::labels(LabelNotStored::because('the bucket did not answer')), $logger);
            self::fail('The failure should reach the worker, which releases the job.');
        } catch (LabelNotStored) {
            $job->assertNotFailed();
        }
        self::assertSame(['Label of shipment {shipmentId} failed on attempt {attempt} of {tries}: {reason}'], $logger->messagesAt('warning'));
    }

    #[Test]
    public function a_job_that_gives_up_leaves_an_error_in_the_log(): void
    {
        $log = Log::spy();

        new GenerateLabelJob('01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d', self::settings())->failed(LabelNotStored::because('the bucket did not answer'));

        $log->shouldHaveReceived('error')->once()->withArgs(static fn(string $message, array $context): bool => $context['shipmentId'] === '01999a1e-3c4d-7a2b-8c9d-0e1f2a3b4c5d');
    }

    #[Test]
    public function a_failure_that_would_repeat_goes_straight_to_failed_jobs(): void
    {
        $job = new GenerateLabelJob((string) ShipmentId::generate(), self::settings())->withFakeQueueInteractions();

        $job->handle(self::labels(InvalidShipment::because('Shipment does not exist.')), new NullLogger());

        $job->assertFailedWith(InvalidShipment::class);
    }

    private static function labels(Throwable $failure): ForGeneratingLabels
    {
        return new readonly class ($failure) implements ForGeneratingLabels {
            public function __construct(private Throwable $failure) {}

            public function generate(ShipmentId $shipment): LabelOutcome
            {
                throw $this->failure;
            }
        };
    }

    private static function settings(): LabelJobSettings
    {
        return LabelJobSettings::of(3, [5, 20], 30);
    }
}
