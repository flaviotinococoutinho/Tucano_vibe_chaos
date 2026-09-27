<?php

declare(strict_types=1);

namespace Tucano\ReadModels\Tests;

use MongoDB\Database;
use MongoDB\Driver\Exception\BulkWriteException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\ReadModels\MongoMigrator;
use Tucano\ReadModels\Tests\Doubles\Mongo;
use UnexpectedValueException;

#[CoversClass(MongoMigrator::class)]
#[Group('integration')]
final class MongoMigratorTest extends TestCase
{
    private Database $database;
    private string $directory;

    protected function setUp(): void
    {
        $this->database = Mongo::freshDatabase();
        $this->directory = sys_get_temp_dir() . '/read-models-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    #[Test]
    public function migrations_run_in_name_order_and_only_once(): void
    {
        $this->writeMigration('002_create_index', "\$database->selectCollection('orders')->createIndex(['customerId' => 1]);");
        $this->writeMigration('001_create_orders', <<<'PHP'
            $database->createCollection('orders', ['validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['customerId'],
            ]]]);
            PHP);
        $migrator = new MongoMigrator($this->database, $this->directory);

        self::assertSame(['001_create_orders', '002_create_index'], $migrator->migrate());
        self::assertSame([], $migrator->migrate());
    }

    #[Test]
    public function the_validator_rejects_documents_outside_the_schema(): void
    {
        $this->writeMigration('001_create_orders', <<<'PHP'
            $database->createCollection('orders', ['validator' => ['$jsonSchema' => [
                'bsonType' => 'object',
                'required' => ['customerId'],
            ]]]);
            PHP);
        (new MongoMigrator($this->database, $this->directory))->migrate();

        $this->expectException(BulkWriteException::class);

        $this->database->selectCollection('orders')->insertOne(['status' => 'paid']);
    }

    #[Test]
    public function a_file_that_is_not_a_migration_stops_the_run(): void
    {
        file_put_contents($this->directory . '/001_broken.php', '<?php return 42;');

        $this->expectException(UnexpectedValueException::class);

        (new MongoMigrator($this->database, $this->directory))->migrate();
    }

    private function writeMigration(string $name, string $body): void
    {
        file_put_contents($this->directory . "/{$name}.php", <<<PHP
            <?php

            use MongoDB\\Database;
            use Tucano\\ReadModels\\Migration;

            return new class implements Migration {
                public function up(Database \$database): void
                {
                    {$body}
                }
            };
            PHP);
    }
}
