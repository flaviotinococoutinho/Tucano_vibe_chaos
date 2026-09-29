<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tracking\Delivery\Adapter\Driving\WebSocket\LiveFollowers;
use Tracking\Delivery\Application\Port\Driving\ForFollowingDeliveries;
use Tracking\Delivery\Domain\CourierPosition;
use Tracking\Delivery\Domain\DeliveryNews;
use Tracking\Delivery\Domain\TrackingCode;

final class LiveFollowersTest extends TestCase
{
    private const string FOLLOWED = 'TX02Q6AGJQ45G00';
    private const string ANOTHER = 'TX02Q6AGJQ45G01';

    /** @var list<array{int, string}> */
    private array $pushed = [];

    /** @var list<array{int, int}> */
    private array $closed = [];

    #[Test]
    public function a_new_follower_gets_the_last_news_right_away(): void
    {
        $followers = $this->followers(CourierPosition::of(TrackingCode::of(self::FOLLOWED), -19.93, -44.05, new DateTimeImmutable('2026-09-28T21:56:13Z'), 3120));

        $followers->follow(7, TrackingCode::of(self::FOLLOWED));
        $followers->welcome(7);

        self::assertCount(1, $this->pushed);
        self::assertSame(7, $this->pushed[0][0]);
        self::assertStringContainsString('"remainingMeters":3120', $this->pushed[0][1]);
    }

    #[Test]
    public function a_news_reaches_only_the_followers_of_its_code(): void
    {
        $followers = $this->followers();
        $followers->follow(7, TrackingCode::of(self::FOLLOWED));
        $followers->follow(8, TrackingCode::of(self::FOLLOWED));
        $followers->follow(9, TrackingCode::of(self::ANOTHER));

        $followers->deliver(self::position(self::FOLLOWED));

        self::assertSame([7, 8], array_column($this->pushed, 0));
        self::assertSame([], $this->closed);
    }

    #[Test]
    public function the_end_of_the_visit_is_sent_and_then_the_connection_closes(): void
    {
        $followers = $this->followers();
        $followers->follow(7, TrackingCode::of(self::FOLLOWED));

        $followers->deliver('{"type":"ended","trackingCode":"' . self::FOLLOWED . '","outcome":"delivered","at":"2026-09-28T21:56:33.101Z"}');
        $followers->deliver(self::position(self::FOLLOWED));

        self::assertCount(1, $this->pushed, 'nothing follows the end');
        self::assertSame([[7, 1000]], $this->closed);
        self::assertSame(0, $followers->count());
    }

    #[Test]
    public function a_connection_that_closed_is_forgotten_and_a_shutdown_lets_everyone_go(): void
    {
        $followers = $this->followers();
        $followers->follow(7, TrackingCode::of(self::FOLLOWED));
        $followers->follow(8, TrackingCode::of(self::ANOTHER));
        $followers->forget(7);

        $followers->deliver(self::position(self::FOLLOWED));
        $followers->letEveryoneGo();

        self::assertSame([], $this->pushed);
        self::assertSame([[8, 1001]], $this->closed);
        self::assertSame(0, $followers->count());
    }

    #[Test]
    public function a_news_that_is_not_one_is_left_alone(): void
    {
        $followers = $this->followers();
        $followers->follow(7, TrackingCode::of(self::FOLLOWED));

        $followers->deliver('{"type":"teleported"}');

        self::assertSame([], $this->pushed);
    }

    private function followers(?DeliveryNews $last = null): LiveFollowers
    {
        return new LiveFollowers(
            function (int $connection, string $frame): bool {
                $this->pushed[] = [$connection, $frame];

                return true;
            },
            function (int $connection, int $code): bool {
                $this->closed[] = [$connection, $code];

                return true;
            },
            new readonly class ($last) implements ForFollowingDeliveries {
                public function __construct(private ?DeliveryNews $last) {}

                public function lastNews(TrackingCode $code): ?DeliveryNews
                {
                    return $this->last;
                }
            },
            new NullLogger(),
        );
    }

    private static function position(string $code): string
    {
        return '{"type":"position","trackingCode":"' . $code . '","latitude":-19.9112,"longitude":-44.0321,"at":"2026-09-28T21:56:14.634Z","remainingMeters":3010}';
    }
}
