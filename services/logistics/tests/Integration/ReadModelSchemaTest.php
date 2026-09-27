<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** The read model collection is created with a JSON Schema validator and its indexes. */
#[Group('integration')]
final class ReadModelSchemaTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->database = $this->app->make(Database::class);
        $this->database->drop();
        self::assertSame(0, Artisan::call('mongo:migrate'));
    }

    #[Test]
    public function documents_outside_the_schema_are_rejected(): void
    {
        $this->expectException(BulkWriteException::class);

        $this->database->selectCollection('shipment_timelines')->insertOne(['trackingCode' => 'not-a-code']);
    }

    #[Test]
    public function running_the_migrations_again_changes_nothing(): void
    {
        self::assertSame(0, Artisan::call('mongo:migrate'));
        self::assertStringContainsString('up to date', Artisan::output());
    }
}
