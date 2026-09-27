<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class FulfillmentCenterSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('fulfillment_centers')->upsert([
            ['code' => 'GRU1', 'name' => 'CD Guarulhos', 'state' => 'SP'],
            ['code' => 'BHZ1', 'name' => 'CD Contagem', 'state' => 'MG'],
        ], ['code'], ['name', 'state']);
    }
}
