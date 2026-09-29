<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use Logistics\Timeline\Adapter\Driven\DynamoTrackingViews;
use Logistics\Timeline\Application\Port\Driven\ForReadingTrackingViews;
use Logistics\Timeline\Domain\TrackingPagesUnavailable;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The public page with DynamoDB out of reach: a quick 503 that says when to come back, never a hanging request. */
final class TrackingOutOfReachTest extends TestCase
{
    #[Test]
    public function a_page_out_of_reach_is_a_503_that_says_when_to_come_back(): void
    {
        $this->app->instance(ForReadingTrackingViews::class, new class implements ForReadingTrackingViews {
            public function find(string $trackingCode): never
            {
                throw TrackingPagesUnavailable::forSeconds(5);
            }
        });

        $this->getJson('/v1/tracking/TX02Q50MATM5G00')
            ->assertServiceUnavailable()
            ->assertHeader('Retry-After', '5')
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'The tracking pages are out of reach; try again in 5 s.');
    }

    #[Test]
    public function the_read_gives_up_at_once_when_dynamodb_refuses_the_connection(): void
    {
        // Nothing listens on port 9 of the test container: the connection is refused on the spot.
        config(['tracking.dynamodb.endpoint' => 'http://127.0.0.1:9']);
        $pages = $this->app->make(DynamoTrackingViews::class);

        $started = microtime(true);
        try {
            $pages->find('TX02Q50MATM5G00');
            self::fail('A page came back with DynamoDB out of reach.');
        } catch (TrackingPagesUnavailable $unavailable) {
            self::assertSame(5, $unavailable->retryAfterSeconds);
        }
        self::assertLessThan(1.0, microtime(true) - $started, 'the read gets one short try, not the retries of the writes');
    }
}
