<?php

declare(strict_types=1);

namespace Tucano\ReadModels;

use DateTimeImmutable;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use UnexpectedValueException;

/**
 * Applies the migration files of a directory in name order and records each
 * one in the _migrations collection, the same way Laravel does for SQL.
 */
final readonly class MongoMigrator
{
    private const string LEDGER = '_migrations';

    public function __construct(private Database $database, private string $directory) {}

    /** @return list<string> names applied in this run */
    public function migrate(): array
    {
        $applied = [];
        foreach ($this->pending() as $name => $file) {
            $this->load($file)->up($this->database);
            $this->database->selectCollection(self::LEDGER)->insertOne([
                '_id' => $name,
                'appliedAt' => new UTCDateTime(new DateTimeImmutable()),
            ]);
            $applied[] = $name;
        }

        return $applied;
    }

    /** @return array<string, string> migration name to file path */
    private function pending(): array
    {
        $files = glob($this->directory . '/*.php') ?: [];
        sort($files);
        $pending = [];
        foreach ($files as $file) {
            $name = basename($file, '.php');
            if ($this->database->selectCollection(self::LEDGER)->countDocuments(['_id' => $name]) === 0) {
                $pending[$name] = $file;
            }
        }

        return $pending;
    }

    private function load(string $file): Migration
    {
        $migration = require $file;
        if (!$migration instanceof Migration) {
            throw new UnexpectedValueException(sprintf('%s must return a %s.', $file, Migration::class));
        }

        return $migration;
    }
}
