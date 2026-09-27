<?php

declare(strict_types=1);

namespace Tucano\ReadModels;

use MongoDB\Collection;
use MongoDB\Driver\Exception\BulkWriteException;

/**
 * Last-writer-wins by version. Events can arrive twice or out of order (two
 * topics, redeliveries), so a projection only applies a change that is newer
 * than what the document already has. Older or repeated changes are ignored.
 */
final readonly class VersionedDocuments
{
    private const int DUPLICATE_KEY = 11000;

    public function __construct(private Collection $collection) {}

    /**
     * @param array<string, mixed> $fields
     *
     * @return bool true when the change was applied, false when the document was already newer
     */
    public function apply(mixed $id, int $version, array $fields): bool
    {
        try {
            $result = $this->collection->updateOne(
                ['_id' => $id, '$or' => [['version' => ['$lt' => $version]], ['version' => ['$exists' => false]]]],
                ['$set' => [...$fields, 'version' => $version]],
                ['upsert' => true],
            );
        } catch (BulkWriteException $error) {
            // The filter did not match because the stored version is newer; the upsert
            // then tried to insert the same _id again. Nothing to do.
            if ($error->getCode() === self::DUPLICATE_KEY || str_contains($error->getMessage(), 'E11000')) {
                return false;
            }

            throw $error;
        }

        return $result->getModifiedCount() + $result->getUpsertedCount() > 0;
    }
}
