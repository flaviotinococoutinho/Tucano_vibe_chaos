<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class CarrierSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('carriers')->upsert([
            ['code' => 'tucano-express', 'name' => 'Tucano Express', 'kind' => 'own_fleet', 'max_weight_grams' => 20_000],
            ['code' => 'ligeirinho', 'name' => 'Ligeirinho Transportes', 'kind' => 'partner', 'max_weight_grams' => 30_000],
            ['code' => 'correio-nacional', 'name' => 'Correio Nacional', 'kind' => 'partner', 'max_weight_grams' => 30_000],
            ['code' => 'carga-pesada', 'name' => 'Carga Pesada Fretes', 'kind' => 'partner', 'max_weight_grams' => 1_000_000],
        ], ['code'], ['name', 'kind', 'max_weight_grams']);
    }
}
