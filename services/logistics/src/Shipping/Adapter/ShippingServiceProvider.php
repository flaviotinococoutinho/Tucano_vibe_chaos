<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter;

use Aws\S3\S3Client;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\Factory as Queues;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Logistics\Shipping\Adapter\Driven\CarrierFakePickups;
use Logistics\Shipping\Adapter\Driven\CarrierFakeTracking;
use Logistics\Shipping\Adapter\Driven\CarrierSelectionChoices;
use Logistics\Shipping\Adapter\Driven\DeduplicatedAlerts;
use Logistics\Shipping\Adapter\Driven\LaravelLabelQueue;
use Logistics\Shipping\Adapter\Driven\LogAndMailAlerts;
use Logistics\Shipping\Adapter\Driven\PostgresCancelledOrders;
use Logistics\Shipping\Adapter\Driven\PostgresCatalogSnapshots;
use Logistics\Shipping\Adapter\Driven\PostgresFulfillmentCenterStates;
use Logistics\Shipping\Adapter\Driven\PostgresJourneyChecks;
use Logistics\Shipping\Adapter\Driven\PostgresShipments;
use Logistics\Shipping\Adapter\Driven\PostgresStalledJourneys;
use Logistics\Shipping\Adapter\Driven\S3Labels;
use Logistics\Shipping\Adapter\Driven\SnowflakeTrackingCodes;
use Logistics\Shipping\Adapter\Driven\ZplLabels;
use Logistics\Shipping\Adapter\Driving\Console\BookPickups;
use Logistics\Shipping\Adapter\Driving\Console\OrderIntake;
use Logistics\Shipping\Adapter\Driving\Console\ReconcileJourneysWorker;
use Logistics\Shipping\Adapter\Driving\Console\RequestLabels;
use Logistics\Shipping\Adapter\Driving\Console\SyncCatalog;
use Logistics\Shipping\Adapter\Driving\Console\WatchStalledJourneysWorker;
use Logistics\Shipping\Adapter\Driving\Http\CarrierWebhookController;
use Logistics\Shipping\Application\Port\Driven\ForChoosingCarriers;
use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Application\Port\Driven\ForFindingStalledJourneys;
use Logistics\Shipping\Application\Port\Driven\ForIssuingTrackingCodes;
use Logistics\Shipping\Application\Port\Driven\ForLocatingFulfillmentCenters;
use Logistics\Shipping\Application\Port\Driven\ForPrintingLabels;
use Logistics\Shipping\Application\Port\Driven\ForQueuingLabels;
use Logistics\Shipping\Application\Port\Driven\ForRaisingAlerts;
use Logistics\Shipping\Application\Port\Driven\ForRecordingJourneyChecks;
use Logistics\Shipping\Application\Port\Driven\ForSchedulingPickups;
use Logistics\Shipping\Application\Port\Driven\ForStoringCancelledOrders;
use Logistics\Shipping\Application\Port\Driven\ForStoringCatalogCopies;
use Logistics\Shipping\Application\Port\Driven\ForStoringLabels;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driven\ForTrackingPickups;
use Logistics\Shipping\Application\Port\Driving\ForBookingPickups;
use Logistics\Shipping\Application\Port\Driving\ForCancellingShipments;
use Logistics\Shipping\Application\Port\Driving\ForCreatingShipments;
use Logistics\Shipping\Application\Port\Driving\ForDispatchingDeliveries;
use Logistics\Shipping\Application\Port\Driving\ForGeneratingLabels;
use Logistics\Shipping\Application\Port\Driving\ForReconcilingJourneys;
use Logistics\Shipping\Application\Port\Driving\ForRecordingDeliveryOutcomes;
use Logistics\Shipping\Application\Port\Driving\ForRecordingHubScans;
use Logistics\Shipping\Application\Port\Driving\ForRecordingPickups;
use Logistics\Shipping\Application\Port\Driving\ForRequestingLabels;
use Logistics\Shipping\Application\Port\Driving\ForReturningToSender;
use Logistics\Shipping\Application\Port\Driving\ForSyncingCatalog;
use Logistics\Shipping\Application\Port\Driving\ForWatchingStalledJourneys;
use Logistics\Shipping\Application\UseCase\BookPickup;
use Logistics\Shipping\Application\UseCase\CancelShipment;
use Logistics\Shipping\Application\UseCase\CreateShipment;
use Logistics\Shipping\Application\UseCase\DispatchForDelivery;
use Logistics\Shipping\Application\UseCase\GenerateLabel;
use Logistics\Shipping\Application\UseCase\ReconcileJourneys;
use Logistics\Shipping\Application\UseCase\RecordDeliveryOutcome;
use Logistics\Shipping\Application\UseCase\RecordHubScan;
use Logistics\Shipping\Application\UseCase\RecordPickup;
use Logistics\Shipping\Application\UseCase\RequestLabel;
use Logistics\Shipping\Application\UseCase\ReturnToSender;
use Logistics\Shipping\Application\UseCase\SyncCatalogProduct;
use Logistics\Shipping\Application\UseCase\WatchStalledJourneys;
use Psr\Log\LoggerInterface;
use Tucano\FeatureFlags\FeatureFlags;
use Tucano\Messaging\Webhook\WebhookSignature;

/** Plugs the Shipping ports into their adapters and registers its workers. */
final class ShippingServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForCreatingShipments::class => CreateShipment::class,
        ForCancellingShipments::class => CancelShipment::class,
        ForSyncingCatalog::class => SyncCatalogProduct::class,
        ForStoringShipments::class => PostgresShipments::class,
        ForStoringCancelledOrders::class => PostgresCancelledOrders::class,
        ForFindingProducts::class => PostgresCatalogSnapshots::class,
        ForStoringCatalogCopies::class => PostgresCatalogSnapshots::class,
        ForIssuingTrackingCodes::class => SnowflakeTrackingCodes::class,
        ForChoosingCarriers::class => CarrierSelectionChoices::class,
        ForRequestingLabels::class => RequestLabel::class,
        ForGeneratingLabels::class => GenerateLabel::class,
        ForPrintingLabels::class => ZplLabels::class,
        ForBookingPickups::class => BookPickup::class,
        ForLocatingFulfillmentCenters::class => PostgresFulfillmentCenterStates::class,
        ForRecordingPickups::class => RecordPickup::class,
        ForRecordingHubScans::class => RecordHubScan::class,
        ForDispatchingDeliveries::class => DispatchForDelivery::class,
        ForRecordingDeliveryOutcomes::class => RecordDeliveryOutcome::class,
        ForReturningToSender::class => ReturnToSender::class,
        ForReconcilingJourneys::class => ReconcileJourneys::class,
        ForRecordingJourneyChecks::class => PostgresJourneyChecks::class,
        ForWatchingStalledJourneys::class => WatchStalledJourneys::class,
        ForFindingStalledJourneys::class => PostgresStalledJourneys::class,
    ];

    public function register(): void
    {
        $this->app->bind(ForStoringLabels::class, fn(): ForStoringLabels => new S3Labels(
            new S3Client([
                'version' => 'latest',
                'region' => (string) config('labels.s3.region'),
                'endpoint' => (string) config('labels.s3.endpoint'),
                // Floci, like MinIO, serves buckets by path and not by subdomain.
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => (string) config('labels.s3.key'), 'secret' => (string) config('labels.s3.secret')],
                'http' => ['timeout' => 5, 'connect_timeout' => 2],
            ]),
            (string) config('labels.bucket'),
            $this->app->make(FeatureFlags::class),
            static fn(): float => random_int(0, PHP_INT_MAX - 1) / PHP_INT_MAX,
        ));
        $this->app->bind(ForSchedulingPickups::class, static fn(): ForSchedulingPickups => new CarrierFakePickups(
            new Client(['base_uri' => (string) config('carriers.url')]),
            (int) config('carriers.timeout_ms'),
        ));
        $this->app->bind(ForTrackingPickups::class, fn(): ForTrackingPickups => new CarrierFakeTracking(
            new Client(['base_uri' => (string) config('carriers.url')]),
            (int) config('carriers.timeout_ms'),
            $this->app->make(LoggerInterface::class),
        ));
        $this->app->when(ReconcileJourneys::class)->needs('$quietSeconds')->giveConfig('carriers.reconciliation.quiet_seconds');
        $this->app->when(WatchStalledJourneys::class)->needs('$stalledAfterSeconds')->giveConfig('journeys.stalled.after_seconds');
        $this->app->when(WatchStalledJourneys::class)->needs('$alertLimit')->giveConfig('journeys.stalled.alert_limit');
        $this->app->when(PostgresStalledJourneys::class)->needs('$statementTimeoutMs')->giveConfig('journeys.stalled.query_timeout_ms');
        $this->app->bind(ForRaisingAlerts::class, fn(): ForRaisingAlerts => new DeduplicatedAlerts(
            new LogAndMailAlerts($this->app->make(LoggerInterface::class), $this->app->make(Mailer::class), (string) config('journeys.alerts.email_to')),
            $this->app->make(Cache::class),
            $this->app->make(LoggerInterface::class),
            (int) config('journeys.stalled.alert_repeat_seconds'),
        ));
        $this->app->bind(WebhookSignature::class, static fn(): WebhookSignature => new WebhookSignature((string) config('carriers.webhook_secret')));
        $this->app->bind(ForQueuingLabels::class, fn(): ForQueuingLabels => new LaravelLabelQueue(
            $this->app->make(Queues::class),
            (string) config('labels.queue.connection'),
            (string) config('labels.queue.name'),
        ));
    }

    public function boot(Router $router): void
    {
        $this->commands([SyncCatalog::class, OrderIntake::class, RequestLabels::class, BookPickups::class, ReconcileJourneysWorker::class, WatchStalledJourneysWorker::class]);
        $router->middleware('api')->group(static function (Router $router): void {
            // No Idempotency-Key here: the event id, through the inbox, plays that part.
            $router->post('/v1/webhooks/carriers', CarrierWebhookController::class);
        });
    }
}
