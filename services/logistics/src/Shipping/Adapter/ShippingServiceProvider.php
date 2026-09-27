<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter;

use Aws\S3\S3Client;
use Illuminate\Contracts\Queue\Factory as Queues;
use Illuminate\Support\ServiceProvider;
use Logistics\Shipping\Adapter\Driven\CarrierSelectionChoices;
use Logistics\Shipping\Adapter\Driven\LaravelLabelQueue;
use Logistics\Shipping\Adapter\Driven\PostgresCancelledOrders;
use Logistics\Shipping\Adapter\Driven\PostgresCatalogSnapshots;
use Logistics\Shipping\Adapter\Driven\PostgresShipments;
use Logistics\Shipping\Adapter\Driven\S3Labels;
use Logistics\Shipping\Adapter\Driven\SnowflakeTrackingCodes;
use Logistics\Shipping\Adapter\Driven\ZplLabels;
use Logistics\Shipping\Adapter\Driving\Console\OrderIntake;
use Logistics\Shipping\Adapter\Driving\Console\RequestLabels;
use Logistics\Shipping\Adapter\Driving\Console\SyncCatalog;
use Logistics\Shipping\Application\Port\Driven\ForChoosingCarriers;
use Logistics\Shipping\Application\Port\Driven\ForFindingProducts;
use Logistics\Shipping\Application\Port\Driven\ForIssuingTrackingCodes;
use Logistics\Shipping\Application\Port\Driven\ForPrintingLabels;
use Logistics\Shipping\Application\Port\Driven\ForQueuingLabels;
use Logistics\Shipping\Application\Port\Driven\ForStoringCancelledOrders;
use Logistics\Shipping\Application\Port\Driven\ForStoringCatalogCopies;
use Logistics\Shipping\Application\Port\Driven\ForStoringLabels;
use Logistics\Shipping\Application\Port\Driven\ForStoringShipments;
use Logistics\Shipping\Application\Port\Driving\ForCancellingShipments;
use Logistics\Shipping\Application\Port\Driving\ForCreatingShipments;
use Logistics\Shipping\Application\Port\Driving\ForGeneratingLabels;
use Logistics\Shipping\Application\Port\Driving\ForRequestingLabels;
use Logistics\Shipping\Application\Port\Driving\ForSyncingCatalog;
use Logistics\Shipping\Application\UseCase\CancelShipment;
use Logistics\Shipping\Application\UseCase\CreateShipment;
use Logistics\Shipping\Application\UseCase\GenerateLabel;
use Logistics\Shipping\Application\UseCase\RequestLabel;
use Logistics\Shipping\Application\UseCase\SyncCatalogProduct;
use Tucano\FeatureFlags\FeatureFlags;

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
        $this->app->bind(ForQueuingLabels::class, fn(): ForQueuingLabels => new LaravelLabelQueue(
            $this->app->make(Queues::class),
            (string) config('labels.queue.connection'),
            (string) config('labels.queue.name'),
        ));
    }

    public function boot(): void
    {
        $this->commands([SyncCatalog::class, OrderIntake::class, RequestLabels::class]);
    }
}
