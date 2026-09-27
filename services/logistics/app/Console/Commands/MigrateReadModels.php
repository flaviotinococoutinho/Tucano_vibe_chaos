<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use MongoDB\Client;
use Symfony\Component\Console\Attribute\AsCommand;
use Tucano\ReadModels\MongoMigrator;

#[AsCommand(name: 'mongo:migrate', description: 'Apply the MongoDB read model migrations in database/mongo')]
final class MigrateReadModels extends Command
{
    public function handle(): int
    {
        $database = (new Client((string) config('read_models.uri')))->selectDatabase((string) config('read_models.database'));
        $applied = (new MongoMigrator($database, database_path('mongo')))->migrate();

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
