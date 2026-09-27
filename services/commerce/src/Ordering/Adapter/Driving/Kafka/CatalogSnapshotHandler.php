<?php

declare(strict_types=1);

namespace Commerce\Ordering\Adapter\Driving\Kafka;

use Commerce\Ordering\Application\CatalogSnapshot;
use Commerce\Ordering\Application\Port\Driving\ForSyncingCatalog;
use Commerce\Ordering\Domain\Product\ProductStatus;
use Commerce\Ordering\Domain\Product\Sku;
use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\EventFields;
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
        $event = IncomingEvent::read($message);
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

    /** A malformed snapshot will never become valid: straight to the dead letter topic, no retries. */
    private function snapshotOf(CloudEvent $event, ReceivedMessage $message): CatalogSnapshot
    {
        try {
            $data = new EventFields($event->data);
            $price = $data->object('price');

            return new CatalogSnapshot(
                $data->text('productId'),
                Sku::of($data->text('sku')),
                $data->text('name'),
                Money::of($price->integer('amount'), Currency::fromCode($price->text('currency'))),
                ProductStatus::from($data->text('status')),
                $data->integer('version'),
            );
        } catch (InvalidArgumentException|DomainError|ValueError $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
    }
}
