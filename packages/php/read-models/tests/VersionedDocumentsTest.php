<?php

declare(strict_types=1);

namespace Tucano\ReadModels\Tests;

use MongoDB\Collection;
use MongoDB\Database;
use MongoDB\Model\BSONDocument;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\ReadModels\Tests\Doubles\Mongo;
use Tucano\ReadModels\VersionedDocuments;

#[CoversClass(VersionedDocuments::class)]
#[Group('integration')]
final class VersionedDocumentsTest extends TestCase
{
    private Database $database;
    private Collection $orders;

    protected function setUp(): void
    {
        $this->database = Mongo::freshDatabase();
        $this->orders = $this->database->selectCollection('order_views');
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    #[Test]
    public function a_newer_change_is_applied(): void
    {
        $documents = new VersionedDocuments($this->orders);

        self::assertTrue($documents->apply('order-1', 1, ['status' => 'pending_payment']));
        self::assertTrue($documents->apply('order-1', 2, ['status' => 'paid']));

        self::assertSame('paid', $this->statusOf('order-1'));
    }

    #[Test]
    public function an_event_that_arrives_late_does_not_overwrite_a_newer_state(): void
    {
        $documents = new VersionedDocuments($this->orders);
        $documents->apply('order-1', 2, ['status' => 'paid']);

        self::assertFalse($documents->apply('order-1', 1, ['status' => 'pending_payment']));
        self::assertSame('paid', $this->statusOf('order-1'));
    }

    #[Test]
    public function a_redelivered_event_changes_nothing(): void
    {
        $documents = new VersionedDocuments($this->orders);
        $documents->apply('order-1', 3, ['status' => 'shipped']);

        self::assertFalse($documents->apply('order-1', 3, ['status' => 'shipped']));
    }

    private function statusOf(string $id): string
    {
        $document = $this->orders->findOne(['_id' => $id]);
        self::assertInstanceOf(BSONDocument::class, $document);

        $status = $document['status'];
        self::assertIsString($status);

        return $status;
    }
}
