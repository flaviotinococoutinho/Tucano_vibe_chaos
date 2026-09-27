<?php

declare(strict_types=1);

namespace Tucano\ReadModels;

use MongoDB\Database;

/** One step of a read model schema: collections, JSON Schema validators and indexes. */
interface Migration
{
    public function up(Database $database): void;
}
