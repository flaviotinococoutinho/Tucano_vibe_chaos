<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;
use Tucano\Messaging\Kafka\Message;
use Tucano\SharedKernel\Messaging\CloudEvent;

#[Group('integration')]
final class RepublishProductsTest extends IntegrationTestCase
{
    #[Test]
    public function it_republishes_every_active_and_discontinued_product(): void
    {
        $exitCode = $this->artisan('catalog:republish');

        self::assertSame(0, $exitCode);
        self::assertStringContainsString('Republished 17 product snapshots to catalog.products.v1.', $this->consoleOutput());
        $events = $this->events();
        self::assertEquals(
            ['active' => 16, 'discontinued' => 1],
            array_count_values(array_map(static fn(CloudEvent $event): string => (string) $event->data['status'], $events)),
        );
        // One correlation id for the whole run, so its events can be traced together.
        self::assertCount(1, array_unique(array_map(static fn(CloudEvent $event): string => $event->correlationId, $events)));
    }

    #[Test]
    public function it_can_republish_a_single_product(): void
    {
        $exitCode = $this->artisan('catalog:republish', ['--sku' => 'ELEC-MP3-001']);

        self::assertSame(0, $exitCode);
        $events = $this->events();
        self::assertCount(1, $events);
        self::assertSame('ELEC-MP3-001', $events[0]->data['sku']);
        self::assertSame('discontinued', $events[0]->data['status']);
    }

    /** @return iterable<string, array{string}> */
    public static function unpublishedSkus(): iterable
    {
        yield 'a draft' => ['HOME-LAMP-001'];
        yield 'an unknown sku' => ['BOOK-NONE-001'];
    }

    #[Test]
    #[DataProvider('unpublishedSkus')]
    public function a_sku_without_a_published_product_is_refused(string $sku): void
    {
        $exitCode = $this->artisan('catalog:republish', ['--sku' => $sku]);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString("No active or discontinued product has the SKU {$sku}.", $this->consoleOutput());
        self::assertSame([], $this->kafka->delivered());
    }

    #[Test]
    public function it_fails_loudly_when_kafka_does(): void
    {
        $this->kafka->failWith('Local: Message timed out');

        $exitCode = $this->artisan('catalog:republish');

        self::assertSame(1, $exitCode);
        self::assertStringContainsString('Kafka did not acknowledge every message: Local: Message timed out', $this->consoleOutput());
    }

    #[Test]
    public function running_it_again_sends_the_same_versions(): void
    {
        $this->artisan('catalog:republish');
        $first = $this->snapshots();

        $this->artisan('catalog:republish');

        // Consumers keep the highest version, so a second copy of each snapshot changes nothing.
        self::assertSame([...$first, ...$first], $this->snapshots());
    }

    /** @return list<CloudEvent> */
    private function events(): array
    {
        return array_map(static fn(Message $message): CloudEvent => CloudEvent::fromJson($message->payload), $this->kafka->delivered());
    }

    /** @return list<array<string, mixed>> */
    private function snapshots(): array
    {
        return array_map(static fn(CloudEvent $event): array => $event->data, $this->events());
    }

    private function consoleOutput(): string
    {
        return $this->app->make(Kernel::class)->output();
    }
}
