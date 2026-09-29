<?php

declare(strict_types=1);

namespace Tests\Integration;

use Illuminate\Support\Facades\Artisan;
use MongoDB\BSON\Binary;
use MongoDB\BSON\Int64;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Ramsey\Uuid\Uuid;
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
    public function a_timeline_with_a_store_outside_the_slug_is_rejected(): void
    {
        $this->expectException(BulkWriteException::class);

        $this->database->selectCollection('shipment_timelines')->insertOne(self::timeline('TX02PWW6JFR5G00', ['store' => 'Sabiá Casa e Esporte']));
    }

    #[Test]
    public function a_timeline_says_its_store_or_none_at_all(): void
    {
        $timelines = $this->database->selectCollection('shipment_timelines');

        $timelines->insertOne(self::timeline('TX02PWW6JFR5G00', ['store' => 'sabia']));
        $timelines->insertOne(self::timeline('TX02PWW6JFR5G01'));

        self::assertSame(2, $timelines->countDocuments());
    }

    #[Test]
    public function running_the_migrations_again_changes_nothing(): void
    {
        self::assertSame(0, Artisan::call('mongo:migrate'));
        self::assertStringContainsString('up to date', Artisan::output());
    }

    /**
     * A timeline with everything the collection requires, and the fields the test adds.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private static function timeline(string $trackingCode, array $fields = []): array
    {
        return [
            '_id' => new Binary(Uuid::uuid7()->getBytes(), Binary::TYPE_UUID),
            'trackingCode' => $trackingCode,
            'orderId' => new Binary(Uuid::uuid7()->getBytes(), Binary::TYPE_UUID),
            'status' => 'created',
            'carrier' => 'tucano-express',
            'events' => [],
            'updatedAt' => new UTCDateTime(),
            'version' => new Int64(1),
            ...$fields,
        ];
    }
}
