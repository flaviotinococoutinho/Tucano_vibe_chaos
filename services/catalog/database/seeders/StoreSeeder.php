<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Reference data (docs/adr/0031-a-store-is-a-tenant.md): a new run renames a store or gives it
 * another tagline or palette, and never duplicates it. The slug is the key, and it never changes.
 * Opening a store is a new line here and a service in Kong.
 */
final class StoreSeeder extends Seeder
{
    /** slug => [name, tagline, palette] */
    private const array STORES = [
        'arara' => ['Arara Livros', 'Livros para quem constrói sistemas.', 'arara'],
        'bemtevi' => ['Bem-te-vi Eletrônicos', 'Eletrônicos para a mesa de trabalho.', 'bemtevi'],
        'sabia' => ['Sabiá Casa e Esporte', 'Da cozinha ao treino, o que o dia pede.', 'sabia'],
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::STORES as $slug => [$name, $tagline, $palette]) {
            $rows[] = ['id' => Uuid::uuid7()->getBytes(), 'slug' => $slug, 'name' => $name, 'tagline' => $tagline, 'palette' => $palette];
        }

        DB::table('stores')->upsert($rows, ['slug'], ['name', 'tagline', 'palette']);
    }
}
