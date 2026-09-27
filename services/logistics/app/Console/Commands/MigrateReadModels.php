<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MongoDB\Database;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\ReadModels\MongoMigrator;

#[AsCommand(name: 'mongo:migrate', description: 'Apply the MongoDB read model migrations in database/mongo')]
final class MigrateReadModels extends Command
{
    public function handle(Database $readModels): int
    {
        $applied = (new MongoMigrator($readModels, database_path('mongo')))->migrate();

        if ($applied === []) {
            $this->components->info('Read models are up to date.');

            return self::SUCCESS;
        }
        foreach ($applied as $migration) {
            $this->components->twoColumnDetail($migration, '<fg=green>DONE</>');
        }

        return self::SUCCESS;
    }
}
