<?php

declare(strict_types=1);

namespace Tests\Unit\Shipping;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Carbon;
use Logistics\Shipping\Adapter\Driven\DeduplicatedAlerts;
use Logistics\Shipping\Application\Alert;
use Logistics\Shipping\Application\Port\Driven\ForRaisingAlerts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Doubles\RecordingLogger;
use Tests\Doubles\Shipping\RecordedAlerts;

final class DeduplicatedAlertsTest extends TestCase
{
    private RecordedAlerts $sent;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        Carbon::setTestNow('2026-09-27T15:00:00Z');
        $this->sent = new RecordedAlerts();
        $this->logger = new RecordingLogger();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
    }

    #[Test]
    public function the_same_news_goes_out_once_per_window_and_new_news_right_away(): void
    {
        $alerts = new DeduplicatedAlerts($this->sent, new CacheRepository(new ArrayStore()), $this->logger, 14400);

        $alerts->raise(self::alert('stalled-journeys:a'));
        $alerts->raise(self::alert('stalled-journeys:a'));
        $alerts->raise(self::alert('stalled-journeys:b'));
        Carbon::setTestNow('2026-09-27T19:00:01Z');
        $alerts->raise(self::alert('stalled-journeys:a'));

        self::assertSame(
            ['stalled-journeys:a', 'stalled-journeys:b', 'stalled-journeys:a'],
            array_map(static fn(Alert $alert): string => $alert->fingerprint, $this->sent->raised),
        );
    }

    #[Test]
    public function an_alert_that_did_not_go_out_opens_no_window_and_the_next_round_tries_again(): void
    {
        $cache = new CacheRepository(new ArrayStore());
        $mailServerDown = new class implements ForRaisingAlerts {
            public function raise(Alert $alert): void
            {
                throw new RuntimeException('Connection could not be established with host "toxiproxy:11025"');
            }
        };

        (new DeduplicatedAlerts($mailServerDown, $cache, $this->logger, 14400))->raise(self::alert('stalled-journeys:a'));
        (new DeduplicatedAlerts($this->sent, $cache, $this->logger, 14400))->raise(self::alert('stalled-journeys:a'));

        self::assertSame(['The alert did not go out, the next round tries again: {message}'], $this->logger->messagesAt('error'));
        self::assertCount(1, $this->sent->raised);
    }

    #[Test]
    public function without_the_cache_the_alert_still_goes_out(): void
    {
        $cache = self::createStub(Repository::class);
        $cache->method('add')->willThrowException(new RuntimeException('Connection refused [tcp://toxiproxy:16379]'));

        (new DeduplicatedAlerts($this->sent, $cache, $this->logger, 14400))->raise(self::alert('stalled-journeys:a'));

        self::assertCount(1, $this->sent->raised);
        self::assertSame(['Alert sent without deduplication: {message}'], $this->logger->messagesAt('warning'));
    }

    private static function alert(string $fingerprint): Alert
    {
        return Alert::of($fingerprint, '2 shipments stalled with the carrier', 'TX02PX83Y5M5G00 picked_up with correio-nacional');
    }
}
