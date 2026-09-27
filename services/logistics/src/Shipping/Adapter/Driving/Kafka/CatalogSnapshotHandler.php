<?php

declare(strict_types=1);

namespace Logistics\Shipping\Adapter\Driving\Kafka;

use Illuminate\Support\Facades\Context;
use InvalidArgumentException;
use Logistics\Shipping\Application\CatalogSnapshot;
use Logistics\Shipping\Application\Port\Driving\ForSyncingCatalog;
use Logistics\Shipping\Domain\Parcel\Dimensions;
use Logistics\Shipping\Domain\Parcel\Weight;
use Logistics\Shipping\Domain\Product\Sku;
use Psr\Log\LoggerInterface;
use Tucano\Messaging\Kafka\IncomingEvent;
use Tucano\Messaging\Kafka\MessageHandler;
use Tucano\Messaging\Kafka\PermanentFailure;
use Tucano\Messaging\Kafka\ReceivedMessage;
use Tucano\SharedKernel\Domain\DomainError;
use Tucano\SharedKernel\Messaging\CloudEvent;
use Tucano\SharedKernel\Messaging\EventFields;

/**
 * Reads catalog.products.v1. The topic is compacted and carries the full state
 * of every product, so a new consumer group rebuilds the whole copy from offset
 * zero, and this handler only needs the weight and the size (tolerant reader).
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

        $snapshot = self::snapshotOf($event, $message);
        $outcome = $this->catalog->sync($snapshot);
        $this->logger->debug('Catalog snapshot {outcome}', [
            'outcome' => strtolower($outcome->name),
            'sku' => (string) $snapshot->sku,
            'version' => $snapshot->version,
        ]);
    }

    /**
     * A malformed snapshot will never become valid: straight to the dead letter topic, no retries.
     *
     * @throws PermanentFailure
     */
    private static function snapshotOf(CloudEvent $event, ReceivedMessage $message): CatalogSnapshot
    {
        try {
            $data = new EventFields($event->data);
            $dimensions = $data->object('dimensions');

            return new CatalogSnapshot(
                $data->uuid('productId'),
                Sku::of($data->text('sku')),
                $data->text('name'),
                Weight::ofGrams($data->integer('weightGrams')),
                Dimensions::ofMillimetres($dimensions->integer('lengthMm'), $dimensions->integer('widthMm'), $dimensions->integer('heightMm')),
                $data->integer('version'),
            );
        } catch (InvalidArgumentException|DomainError $invalid) {
            throw IncomingEvent::unreadable($message, $invalid);
        }
    }
}
