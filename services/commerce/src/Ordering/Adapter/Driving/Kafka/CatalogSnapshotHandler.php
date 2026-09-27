<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Kafka;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\Port\Driving\ForSyncingCatalog;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use JsonException;
use Psr\Log\LoggerInterface;
use Throwable;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\InvalidCloudEvent;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;
use ValueError;

/**
 * Reads catalog.products.v1. The topic is compacted and carries the full state
 * of every product, so a new consumer group rebuilds the whole copy from offset
 * zero, and this handler only needs the fields checkout uses (tolerant reader).
 */
final readonly class CatalogSnapshotHandler implements MessageHandler
{
    private const string TYPE = 'tucano.catalog.product.snapshot';

    public function __construct(private ForSyncingCatalog $catalog, private LoggerInterface $logger) {}

    public function handle(ReceivedMessage $message): void
    {
        if ($message->payload === '') {
            // A tombstone. The catalog never deletes products today, so there is nothing to remove.
            return;
        }
        $event = $this->read($message);
        if ($event->type !== self::TYPE) {
            return;
        }
        Context::add('correlation_id', $event->correlationId);

        $snapshot = $this->snapshotOf($event, $message);
        $outcome = $this->catalog->sync($snapshot);
        $this->logger->debug('Catalog snapshot {outcome}', [
            'outcome' => strtolower($outcome->name),
            'sku' => (string) $snapshot->sku,
            'version' => $snapshot->version,
        ]);
    }

    private function read(ReceivedMessage $message): CloudEvent
    {
        try {
            return CloudEvent::fromJson($message->payload);
        } catch (InvalidCloudEvent|JsonException $invalid) {
            throw self::unreadable($message, $invalid);
        }
    }

    /** A malformed snapshot will never become valid: straight to the dead letter topic, no retries. */
    private function snapshotOf(CloudEvent $event, ReceivedMessage $message): CatalogSnapshot
    {
        try {
            $data = $event->data;
            $price = $data['price'] ?? null;
            if (!is_array($price)) {
                throw new InvalidArgumentException('The snapshot has no price.');
            }

            return new CatalogSnapshot(
                self::text($data, 'productId'),
                Sku::of(self::text($data, 'sku')),
                self::text($data, 'name'),
                Money::of(self::integer($price, 'amount'), Currency::fromCode(self::text($price, 'currency'))),
                ProductStatus::from(self::text($data, 'status')),
                self::integer($data, 'version'),
            );
        } catch (InvalidArgumentException|DomainError|ValueError $invalid) {
            throw self::unreadable($message, $invalid);
        }
    }

    /** @param array<mixed> $fields */
    private static function text(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : throw new InvalidArgumentException(sprintf('The snapshot has no %s.', $name));
    }

    /** @param array<mixed> $fields */
    private static function integer(array $fields, string $name): int
    {
        $value = $fields[$name] ?? null;

        return is_int($value) ? $value : throw new InvalidArgumentException(sprintf('The snapshot has no integer %s.', $name));
    }

    private static function unreadable(ReceivedMessage $message, Throwable $reason): PermanentFailure
    {
        return new PermanentFailure(
            sprintf('Unreadable catalog snapshot at %s[%d]@%d: %s', $message->topic, $message->partition, $message->offset, $reason->getMessage()),
            previous: $reason,
        );
    }
}
