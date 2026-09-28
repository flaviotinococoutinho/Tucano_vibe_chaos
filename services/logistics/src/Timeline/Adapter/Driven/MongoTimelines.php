<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driven;

use Logistics\Timeline\Application\Port\Driven\ForStoringTimelines;
use Logistics\Timeline\Application\TimelineNews;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use Ramsey\Uuid\Uuid;

/**
 * logistics_read.shipment_timelines: one document per shipment, with every step
 * in the order Logistics published them. The filter leaves out a document that
 * already has the event, so a redelivery turns into an upsert of an _id that
 * exists, and the duplicate key says "already there".
 */
final readonly class MongoTimelines implements ForStoringTimelines
{
    private const int DUPLICATE_KEY = 11000;

    /** The collection requires a carrier; only shipment.created carries it. */
    private const string CARRIER_NOT_SEEN_YET = 'unknown';

    private Collection $timelines;

    public function __construct(Database $readModels)
    {
        $this->timelines = $readModels->selectCollection('shipment_timelines');
    }

    public function append(TimelineNews $news): bool
    {
        $step = $news->step;
        $at = new UTCDateTime($step->at);
        $event = ['eventId' => $news->eventId, 'status' => $step->status->value, 'at' => $at, 'location' => $step->hub, 'note' => $step->reason];
        if ($step->attempt !== null) {
            $event['attempt'] = $step->attempt;
        }
        $carrier = $news->carrier === null ? [] : ['carrier' => $news->carrier];

        try {
            $result = $this->timelines->updateOne(
                ['_id' => self::uuid($news->shipmentId), 'events.eventId' => ['$ne' => $news->eventId]],
                [
                    '$push' => ['events' => $event],
                    '$set' => ['status' => $step->status->value, 'updatedAt' => $at, ...$carrier],
                    '$setOnInsert' => ['trackingCode' => $news->trackingCode, 'orderId' => self::uuid($news->orderId), ...($carrier === [] ? ['carrier' => self::CARRIER_NOT_SEEN_YET] : [])],
                    '$inc' => ['version' => new Int64(1)],
                ],
                ['upsert' => true],
            );
        } catch (BulkWriteException $error) {
            if ($error->getCode() === self::DUPLICATE_KEY || str_contains($error->getMessage(), 'E11000')) {
                return false;
            }

            throw $error;
        }

        return $result->getModifiedCount() + $result->getUpsertedCount() > 0;
    }

    private static function uuid(string $id): Binary
    {
        return new Binary(Uuid::fromString($id)->getBytes(), Binary::TYPE_UUID);
    }
}
