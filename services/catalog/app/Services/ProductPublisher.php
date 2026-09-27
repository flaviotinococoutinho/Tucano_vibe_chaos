<?php

declare(strict_types=1);

namespace App\Services;

use App\Logging\LogContext;
use App\Models\Product;
use Ramsey\Uuid\Uuid;
use Tucano\Messaging\Kafka\DeliveryFailed;
use Tucano\Messaging\Kafka\Message;
use Tucano\Messaging\Kafka\Producer;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Time\Clock;

/**
 * Sends product snapshots to the compacted topic, keyed by product id. The catalog
 * has no outbox on purpose (docs/adr/0008-transactional-outbox.md): this runs after
 * MySQL committed, and a failure here leaves the topic behind the database.
 */
final readonly class ProductPublisher
{
    public const string TOPIC = 'catalog.products.v1';
    private const string SOURCE = '/catalog';

    public function __construct(
        private Producer $producer,
        private Clock $clock,
        private LogContext $logContext,
    ) {}

    /**
     * Sends one snapshot per product and waits until Kafka acknowledges all of them.
     *
     * @throws DeliveryFailed
     */
    public function publish(Product ...$products): void
    {
        foreach ($products as $product) {
            $this->producer->send(Message::fromCloudEvent(self::TOPIC, $this->snapshotOf($product)));
        }
        $this->producer->flush();
    }

    private function snapshotOf(Product $product): CloudEvent
    {
        return new CloudEvent(
            Uuid::uuid7()->toString(),
            self::SOURCE,
            ProductSnapshot::TYPE,
            $product->id->toString(),
            $this->clock->now(),
            // Requests and the republish command set one; anything else starts a new correlation.
            $this->logContext->get(LogContext::CORRELATION_ID) ?? Uuid::uuid7()->toString(),
            null,
            ProductSnapshot::of($product),
        );
    }
}
