<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/** Reference data: a new run renames a category, never duplicates it. */
final class CategorySeeder extends Seeder
{
    private const array CATEGORIES = [
        'books' => 'Livros',
        'electronics' => 'Eletrônicos',
        'home' => 'Casa',
        'sports' => 'Esporte',
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::CATEGORIES as $slug => $name) {
            $rows[] = ['id' => Uuid::uuid7()->getBytes(), 'slug' => $slug, 'name' => $name];
        }

        DB::table('categories')->upsert($rows, ['slug'], ['name']);
    }
}
