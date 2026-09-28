<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter;

use Aws\DynamoDb\DynamoDbClient;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Logistics\Timeline\Adapter\Driven\DynamoTrackingViews;
use Logistics\Timeline\Adapter\Driven\MongoTimelines;
use Logistics\Timeline\Adapter\Driving\Console\ProjectTimelines;
use Logistics\Timeline\Adapter\Driving\Console\UpdateTrackingPages;
use Logistics\Timeline\Adapter\Driving\Http\TrackingController;
use Logistics\Timeline\Application\Port\Driven\ForPublishingTrackingViews;
use Logistics\Timeline\Application\Port\Driven\ForReadingTrackingViews;
use Logistics\Timeline\Application\Port\Driven\ForStoringTimelines;
use Logistics\Timeline\Application\Port\Driving\ForProjectingTimelines;
use Logistics\Timeline\Application\Port\Driving\ForTrackingShipments;
use Logistics\Timeline\Application\Port\Driving\ForUpdatingTrackingPages;
use Logistics\Timeline\Application\UseCase\ProjectTimeline;
use Logistics\Timeline\Application\UseCase\TrackShipment;
use Logistics\Timeline\Application\UseCase\UpdateTrackingPage;

/** The read side of shipments (UC-SHP-10): the projection into MongoDB and DynamoDB, and the public tracking page. */
final class TimelineServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public array $bindings = [
        ForProjectingTimelines::class => ProjectTimeline::class,
        ForUpdatingTrackingPages::class => UpdateTrackingPage::class,
        ForTrackingShipments::class => TrackShipment::class,
        ForStoringTimelines::class => MongoTimelines::class,
        ForPublishingTrackingViews::class => DynamoTrackingViews::class,
        ForReadingTrackingViews::class => DynamoTrackingViews::class,
    ];

    public function register(): void
    {
        $this->app->singleton(DynamoTrackingViews::class, static fn(): DynamoTrackingViews => new DynamoTrackingViews(
            new DynamoDbClient([
                'version' => 'latest',
                'region' => (string) config('tracking.dynamodb.region'),
                'endpoint' => (string) config('tracking.dynamodb.endpoint'),
                'credentials' => ['key' => (string) config('tracking.dynamodb.key'), 'secret' => (string) config('tracking.dynamodb.secret')],
                'http' => ['timeout' => (int) config('tracking.dynamodb.timeout_ms') / 1_000, 'connect_timeout' => (int) config('tracking.dynamodb.connect_timeout_ms') / 1_000],
            ]),
            (string) config('tracking.dynamodb.table'),
            (int) config('tracking.page_retention_days'),
        ));
    }

    public function boot(Router $router): void
    {
        $this->commands([ProjectTimelines::class, UpdateTrackingPages::class]);
        $router->middleware('api')->group(static function (Router $router): void {
            $router->get('/v1/tracking/{trackingCode}', TrackingController::class);
        });
    }
}
