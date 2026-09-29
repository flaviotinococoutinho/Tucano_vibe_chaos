<?php

declare(strict_types=1);

namespace Tests\Integration\Timeline;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use DateTimeImmutable;
use Logistics\Timeline\Adapter\Driven\DynamoTrackingViews;
use Logistics\Timeline\Application\TimelineNews;
use Logistics\Timeline\Domain\JourneyStatus;
use Logistics\Timeline\Domain\Place;
use Logistics\Timeline\Domain\TimelineStep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\ShipmentEvents;
use Tests\TestCase;
use Tucano\SharedKernel\Identity\Snowflake\NodeId;
use Tucano\SharedKernel\Identity\Snowflake\Snowflake;

/** The public page against Floci's DynamoDB. It runs when FLOCI_ENDPOINT says where Floci is. */
#[Group('integration')]
final class DynamoTrackingViewsTest extends TestCase
{
    private const string TABLE = 'tracking_lookup';

    private string $trackingCode;

    protected function setUp(): void
    {
        parent::setUp();
        $endpoint = (string) getenv('FLOCI_ENDPOINT');
        if ($endpoint === '') {
            self::markTestSkipped('Set FLOCI_ENDPOINT to run the DynamoDB tests against Floci.');
        }
        config(['tracking.dynamodb.endpoint' => $endpoint]);
        self::createTableUnlessThere(self::client($endpoint));
        // A code of its own for every run: the table outlives the tests.
        $this->trackingCode = 'TX' . Snowflake::compose((int) (microtime(true) * 1000), new NodeId(1, 12), random_int(0, 4095))->toBase32();
    }

    #[Test]
    public function the_page_grows_one_step_per_event_and_reads_back_by_its_code(): void
    {
        $pages = $this->app->make(DynamoTrackingViews::class);

        self::assertTrue($pages->append($this->news('evt_1', TimelineStep::of(JourneyStatus::Created, new DateTimeImmutable('2026-09-27T12:00:00Z')), 'tucano-express')));
        self::assertTrue($pages->append($this->news('evt_2', TimelineStep::of(JourneyStatus::DeliveryFailed, new DateTimeImmutable('2026-09-27T16:00:00Z'), attempt: 1, reason: 'recipient_absent'))));
        self::assertFalse($pages->append($this->news('evt_2', TimelineStep::of(JourneyStatus::DeliveryFailed, new DateTimeImmutable('2026-09-27T16:00:00Z'), attempt: 1, reason: 'recipient_absent'))));

        $page = $pages->find($this->trackingCode);
        self::assertNotNull($page);
        self::assertSame([
            'trackingCode' => $this->trackingCode,
            'store' => 'sabia',
            'status' => 'delivery_failed',
            'carrier' => 'tucano-express',
            'destination' => ['municipality' => 'Betim', 'state' => 'MG'],
            'updatedAt' => '2026-09-27T16:00:00.000+00:00',
            'steps' => [
                ['status' => 'created', 'at' => '2026-09-27T12:00:00.000+00:00'],
                ['status' => 'delivery_failed', 'at' => '2026-09-27T16:00:00.000+00:00', 'attempt' => 1, 'reason' => 'recipient_absent'],
            ],
        ], $page->toArray());
        self::assertNull($pages->find('TX0000000000000'));
    }

    #[Test]
    public function a_page_from_before_the_stores_belongs_to_none(): void
    {
        $pages = $this->app->make(DynamoTrackingViews::class);

        $pages->append($this->news('evt_1', TimelineStep::of(JourneyStatus::PickedUp, new DateTimeImmutable('2026-09-27T13:00:00Z')), store: null));

        self::assertNull($pages->find($this->trackingCode)?->store);
        self::assertNotNull($pages->find($this->trackingCode));
    }

    #[Test]
    public function the_public_endpoint_answers_the_page_or_a_problem(): void
    {
        $this->app->make(DynamoTrackingViews::class)->append($this->news('evt_1', TimelineStep::of(JourneyStatus::PickedUp, new DateTimeImmutable('2026-09-27T13:00:00Z'))));

        $this->getJson('/v1/tracking/' . strtolower($this->trackingCode))
            ->assertOk()
            ->assertJsonPath('status', 'picked_up')
            ->assertJsonPath('store', 'sabia')
            ->assertJsonPath('carrier', null);
        $this->getJson('/v1/tracking/TX0000000000000')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json');
    }

    #[Test]
    public function a_store_reads_its_pages_and_no_other(): void
    {
        $this->app->make(DynamoTrackingViews::class)->append($this->news('evt_1', TimelineStep::of(JourneyStatus::PickedUp, new DateTimeImmutable('2026-09-27T13:00:00Z'))));

        $this->getJson('/v1/stores/sabia/tracking/' . $this->trackingCode)
            ->assertOk()
            ->assertJsonPath('trackingCode', $this->trackingCode)
            ->assertJsonPath('store', 'sabia');
        $this->getJson('/v1/stores/arara/tracking/' . $this->trackingCode)
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', sprintf('No shipment is tracked as %s, or its news has not reached the tracking page yet.', $this->trackingCode));
    }

    private function news(string $eventId, TimelineStep $step, ?string $carrier = null, ?string $store = 'sabia'): TimelineNews
    {
        return TimelineNews::of(ShipmentEvents::SHIPMENT, ShipmentEvents::ORDER, $this->trackingCode, $store, $eventId, $step, $carrier, $carrier === null ? null : Place::of('Betim', 'MG'));
    }

    private static function client(string $endpoint): DynamoDbClient
    {
        return new DynamoDbClient([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $endpoint,
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'http' => ['timeout' => 5, 'connect_timeout' => 2],
        ]);
    }

    /** The stack creates the table on start (infra/floci/dynamodb); a bare Floci in the CI does not. */
    private static function createTableUnlessThere(DynamoDbClient $dynamo): void
    {
        try {
            $dynamo->createTable([
                'TableName' => self::TABLE,
                'AttributeDefinitions' => [['AttributeName' => 'trackingCode', 'AttributeType' => 'S']],
                'KeySchema' => [['AttributeName' => 'trackingCode', 'KeyType' => 'HASH']],
                'BillingMode' => 'PAY_PER_REQUEST',
            ]);
        } catch (DynamoDbException $exists) {
            if ($exists->getAwsErrorCode() !== 'ResourceInUseException') {
                throw $exists;
            }
        }
    }
}
