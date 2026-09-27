<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;
use Tucano\Messaging\Kafka\Message;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

#[Group('integration')]
final class ProductPublishingTest extends IntegrationTestCase
{
    #[Test]
    public function a_change_sends_the_full_state_of_the_product(): void
    {
        $this->app->instance(Clock::class, new FrozenClock('2026-09-28T10:15:30.123456Z'));
        $id = (string) $this->productRow('BOOK-DDD-001')->id_text;

        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['price' => ['amount' => 17990, 'currency' => 'BRL']], [
            'X-Correlation-Id' => 'req-42#7',
        ]);

        $this->response->assertOk();
        self::assertCount(1, $this->kafka->delivered());
        $message = $this->kafka->delivered()[0];
        self::assertSame('catalog.products.v1', $message->topic);
        self::assertSame($id, $message->key);
        self::assertSame('application/cloudevents+json', $message->headers['content-type']);
        $event = CloudEvent::fromJson($message->payload);
        self::assertSame('tucano.catalog.product.snapshot', $event->type);
        self::assertSame('/catalog', $event->source);
        self::assertSame($id, $event->subject);
        self::assertSame('req-42#7', $event->correlationId);
        self::assertSame([
            'productId' => $id,
            'sku' => 'BOOK-DDD-001',
            'name' => 'Domain-Driven Design',
            'status' => 'active',
            'category' => 'books',
            'price' => ['amount' => 17990, 'currency' => 'BRL'],
            'weightGrams' => 1100,
            'dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => 40],
            'version' => 2,
            'updatedAt' => '2026-09-28T10:15:30.123Z',
        ], $event->data);
    }

    #[Test]
    public function every_move_of_a_published_product_sends_the_new_state(): void
    {
        $this->json('POST', '/v1/products/HOME-LAMP-001/activate');
        $this->json('POST', '/v1/products/HOME-LAMP-001/discontinue');
        $this->json('POST', '/v1/products/HOME-LAMP-001/activate');

        self::assertSame(
            [['active', 2], ['discontinued', 3], ['active', 4]],
            array_map(self::statusAndVersion(...), $this->kafka->delivered()),
        );
    }

    #[Test]
    public function drafts_are_never_published(): void
    {
        $this->json('POST', '/v1/products', [
            'sku' => 'BOOK-REF-001',
            'name' => 'Refactoring',
            'category' => 'books',
            'price' => ['amount' => 15990, 'currency' => 'BRL'],
            'weightGrams' => 900,
            'dimensions' => ['lengthMm' => 235, 'widthMm' => 180, 'heightMm' => 30],
        ]);
        $this->response->assertCreated();
        $this->json('PATCH', '/v1/products/HOME-LAMP-001', ['name' => 'Luminária articulada']);
        $this->response->assertOk();

        self::assertSame([], $this->kafka->delivered());
    }

    #[Test]
    public function the_change_stands_when_kafka_is_down(): void
    {
        $logs = $this->captureLogs();
        $this->kafka->failWith('Local: Broker transport failure');
        $id = (string) $this->productRow('BOOK-DDD-001')->id_text;

        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['price' => ['amount' => 17990, 'currency' => 'BRL']]);

        $this->response->assertOk()->assertJsonPath('version', 2);
        self::assertSame(17990, (int) $this->productRow('BOOK-DDD-001')->price_cents);
        self::assertSame([], $this->kafka->delivered());
        self::assertTrue($logs->hasWarning([
            'message' => 'product snapshot not published',
            'context' => [
                'product_id' => $id,
                'sku' => 'BOOK-DDD-001',
                'version' => 2,
                'error' => 'Kafka did not acknowledge every message: Local: Broker transport failure',
            ],
        ]));
    }

    /** @return array{mixed, mixed} */
    private static function statusAndVersion(Message $message): array
    {
        $data = CloudEvent::fromJson($message->payload)->data;

        return [$data['status'] ?? null, $data['version'] ?? null];
    }
}
