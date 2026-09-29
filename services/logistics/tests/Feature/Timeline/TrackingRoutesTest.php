<?php

declare(strict_types=1);

namespace Tests\Feature\Timeline;

use DateTimeImmutable;
use Logistics\Timeline\Application\Port\Driven\ForReadingTrackingViews;
use Logistics\Timeline\Domain\JourneyStatus;
use Logistics\Timeline\Domain\Place;
use Logistics\Timeline\Domain\TimelineStep;
use Logistics\Timeline\Domain\TrackingView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Doubles\Timeline\InMemoryPages;
use Tests\TestCase;

/**
 * The two doors to the public page (ADR 0031): platform-wide, which says whose page it is, and
 * through a store, which opens only for the pages of that store and, for any other, answers
 * exactly what it answers for a code nobody knows.
 */
final class TrackingRoutesTest extends TestCase
{
    private const string OF_SABIA = 'TX02PWW6JFR5G00';

    private const string FROM_BEFORE_THE_STORES = 'TX02PWW6JFR5G01';

    private InMemoryPages $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pages = new InMemoryPages()->put(self::page(self::OF_SABIA, 'sabia'))->put(self::page(self::FROM_BEFORE_THE_STORES, null));
        $this->app->instance(ForReadingTrackingViews::class, $this->pages);
    }

    #[Test]
    public function platform_wide_the_page_says_the_store_it_belongs_to(): void
    {
        $this->getJson('/v1/tracking/' . self::OF_SABIA)
            ->assertOk()
            ->assertJsonPath('trackingCode', self::OF_SABIA)
            ->assertJsonPath('store', 'sabia')
            ->assertJsonPath('status', 'in_transit');

        $page = $this->getJson('/v1/tracking/' . self::FROM_BEFORE_THE_STORES)->assertOk()->json();
        self::assertIsArray($page);
        self::assertArrayHasKey('store', $page);
        self::assertNull($page['store'], 'A shipment from before the stores belongs to none.');
    }

    #[Test]
    public function a_store_reads_its_own_page_with_the_same_body(): void
    {
        $platformWide = $this->getJson('/v1/tracking/' . self::OF_SABIA)->assertOk()->json();
        self::assertIsArray($platformWide);

        $this->getJson('/v1/stores/sabia/tracking/' . strtolower(self::OF_SABIA))
            ->assertOk()
            ->assertExactJson($platformWide);
    }

    /** @return iterable<string, array{string, string}> */
    public static function outsideTheStore(): iterable
    {
        yield 'a code of another store' => ['arara', self::OF_SABIA];
        yield 'a code from before the stores' => ['sabia', self::FROM_BEFORE_THE_STORES];
        yield 'a store nobody opened' => ['tucano', self::OF_SABIA];
        yield 'the store by its name' => ['Sabia', self::OF_SABIA];
    }

    #[Test]
    #[DataProvider('outsideTheStore')]
    public function a_code_outside_the_store_is_the_same_404_as_a_code_nobody_knows(string $store, string $code): void
    {
        $uri = sprintf('/v1/stores/%s/tracking/%s', $store, $code);
        $outside = $this->getJson($uri, ['X-Correlation-Id' => 'req-7#1']);
        $this->pages->forget($code);

        $unknown = $this->getJson($uri, ['X-Correlation-Id' => 'req-7#1']);

        $unknown->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
        self::assertSame(
            [$unknown->getStatusCode(), $unknown->headers->get('Content-Type'), $unknown->getContent()],
            [$outside->getStatusCode(), $outside->headers->get('Content-Type'), $outside->getContent()],
            'The answer must not tell that the code exists in another store.',
        );
    }

    private static function page(string $trackingCode, ?string $store): TrackingView
    {
        return TrackingView::of($trackingCode, $store, JourneyStatus::InTransit, 'correio-nacional', Place::of('Rio de Janeiro', 'RJ'), new DateTimeImmutable('2026-09-29T10:00:00Z'), [
            TimelineStep::of(JourneyStatus::Created, new DateTimeImmutable('2026-09-29T09:00:00Z')),
            TimelineStep::of(JourneyStatus::InTransit, new DateTimeImmutable('2026-09-29T10:00:00Z'), hub: 'Hub Contagem (MG)'),
        ]);
    }
}
