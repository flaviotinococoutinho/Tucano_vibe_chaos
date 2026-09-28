<?php

declare(strict_types=1);

namespace Logistics\Timeline\Adapter\Driven;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\Marshaler;
use DateTimeImmutable;
use Logistics\Timeline\Application\Port\Driven\ForPublishingTrackingViews;
use Logistics\Timeline\Application\Port\Driven\ForReadingTrackingViews;
use Logistics\Timeline\Application\TimelineNews;
use Logistics\Timeline\Domain\JourneyStatus;
use Logistics\Timeline\Domain\Place;
use Logistics\Timeline\Domain\TimelineStep;
use Logistics\Timeline\Domain\TrackingView;
use Tucano\SharedKernel\Messaging\EventFields;

/**
 * tracking_lookup in DynamoDB: one item per tracking code, read by key, never
 * queried. Each step goes in with one UpdateItem: the list grows with
 * list_append, the event id joins the "seen" set, and the condition refuses an
 * event the set already has. The item expires some days after its last step.
 */
final readonly class DynamoTrackingViews implements ForPublishingTrackingViews, ForReadingTrackingViews
{
    private Marshaler $marshaler;

    public function __construct(private DynamoDbClient $dynamo, private string $table, private int $keepDays)
    {
        $this->marshaler = new Marshaler();
    }

    public function append(TimelineNews $news): bool
    {
        $step = $news->step;
        $set = ['#status = :status', 'updatedAt = :at', 'steps = list_append(if_not_exists(steps, :none), :step)', 'expiresAt = :expires', 'shipmentId = :shipment'];
        $values = [
            ':status' => ['S' => $step->status->value],
            ':at' => ['S' => $step->at->format(DATE_RFC3339_EXTENDED)],
            ':none' => ['L' => []],
            ':step' => ['L' => [['M' => $this->marshaler->marshalItem($step->toArray())]]],
            ':expires' => ['N' => (string) $step->at->modify(sprintf('+%d days', $this->keepDays))->getTimestamp()],
            ':shipment' => ['S' => $news->shipmentId],
            ':event' => ['S' => $news->eventId],
            ':seen' => ['SS' => [$news->eventId]],
        ];
        if ($news->carrier !== null) {
            $set[] = 'carrier = :carrier';
            $values[':carrier'] = ['S' => $news->carrier];
        }
        if ($news->destination !== null) {
            $set[] = 'destination = :destination';
            $values[':destination'] = ['M' => $this->marshaler->marshalItem($news->destination->toArray())];
        }

        try {
            $this->dynamo->updateItem([
                'TableName' => $this->table,
                'Key' => ['trackingCode' => ['S' => $news->trackingCode]],
                'UpdateExpression' => 'SET ' . implode(', ', $set) . ' ADD seen :seen',
                'ConditionExpression' => 'attribute_not_exists(seen) OR NOT contains(seen, :event)',
                'ExpressionAttributeNames' => ['#status' => 'status'],
                'ExpressionAttributeValues' => $values,
            ]);
        } catch (DynamoDbException $error) {
            if ($error->getAwsErrorCode() === 'ConditionalCheckFailedException') {
                return false;
            }

            throw $error;
        }

        return true;
    }

    public function find(string $trackingCode): ?TrackingView
    {
        $item = $this->dynamo->getItem(['TableName' => $this->table, 'Key' => ['trackingCode' => ['S' => $trackingCode]]])['Item'] ?? null;
        if (!is_array($item)) {
            return null;
        }
        /** @var array<string, mixed> $fields */
        $fields = $this->marshaler->unmarshalItem($item);
        $page = new EventFields($fields);
        $destination = isset($fields['destination']) ? $page->object('destination') : null;

        return TrackingView::of(
            $page->text('trackingCode'),
            JourneyStatus::from($page->text('status')),
            $page->optionalText('carrier'),
            $destination === null ? null : Place::of($destination->text('municipality'), $destination->text('state')),
            new DateTimeImmutable($page->text('updatedAt')),
            array_map(static fn(EventFields $step): TimelineStep => TimelineStep::of(
                JourneyStatus::from($step->text('status')),
                new DateTimeImmutable($step->text('at')),
                $step->optionalText('hub'),
                $step->optionalNumber('attempt') === null ? null : (int) $step->optionalNumber('attempt'),
                $step->optionalText('reason'),
            ), $page->objects('steps')),
        );
    }
}
