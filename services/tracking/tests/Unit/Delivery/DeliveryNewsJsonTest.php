<?php

declare(strict_types=1);

namespace Tests\Unit\Delivery;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tracking\Delivery\Adapter\DeliveryNewsJson;
use Tracking\Delivery\Domain\CourierPosition;
use Tracking\Delivery\Domain\DeliveryEnded;
use Tracking\Delivery\Domain\VisitOutcome;
use Tracking\Platform\Http\ValidationFailed;

final class DeliveryNewsJsonTest extends TestCase
{
    #[Test]
    public function a_position_reads_and_writes_the_way_the_contract_does(): void
    {
        $json = '{"type":"position","trackingCode":"TX02Q6AGJQ45G00","latitude":-19.9112,"longitude":-44.0321,"at":"2026-09-28T21:56:13.634Z","remainingMeters":3120}';

        $news = DeliveryNewsJson::decode($json);

        self::assertInstanceOf(CourierPosition::class, $news);
        self::assertSame('TX02Q6AGJQ45G00', $news->trackingCode()->value);
        self::assertSame($json, DeliveryNewsJson::encode($news));
    }

    #[Test]
    public function the_end_of_a_visit_carries_its_outcome_and_any_offset_becomes_utc(): void
    {
        $news = DeliveryNewsJson::decode('{"type":"ended","trackingCode":"TX02Q6AGJQ45G00","outcome":"delivery_failed","at":"2026-09-28T18:56:33-03:00"}');

        self::assertInstanceOf(DeliveryEnded::class, $news);
        self::assertSame(VisitOutcome::DeliveryFailed, $news->outcome);
        self::assertSame('{"type":"ended","trackingCode":"TX02Q6AGJQ45G00","outcome":"delivery_failed","at":"2026-09-28T21:56:33.000Z"}', DeliveryNewsJson::encode($news));
    }

    #[Test]
    public function every_mistake_of_a_report_is_named_at_once(): void
    {
        try {
            DeliveryNewsJson::decode('{"type":"position","trackingCode":"TX0I","latitude":91,"longitude":-44,"at":"yesterday","remainingMeters":-1}');
            self::fail('A report with four wrong fields was taken.');
        } catch (ValidationFailed $invalid) {
            self::assertSame(['trackingCode', 'latitude', 'at', 'remainingMeters'], array_keys($invalid->errors));
        }
    }

    #[Test]
    public function what_is_not_a_delivery_news_is_refused(): void
    {
        foreach (['not json', '[1, 2]', '{"type":"teleported"}', '{"type":"ended","trackingCode":"TX02Q6AGJQ45G00","outcome":"lost","at":"2026-09-28T21:56:33Z"}'] as $body) {
            try {
                DeliveryNewsJson::decode($body);
                self::fail(sprintf('%s was taken as a delivery news.', $body));
            } catch (ValidationFailed $invalid) {
                self::assertNotSame([], $invalid->errors);
            }
        }
    }
}
