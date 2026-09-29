<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Every product points at a category and a store, so both come first.
        $this->call([CategorySeeder::class, StoreSeeder::class, ProductSeeder::class]);
    }
}
