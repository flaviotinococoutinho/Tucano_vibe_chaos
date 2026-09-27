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
            [
                'code' => 'GRU1', 'name' => 'CD Guarulhos', 'city' => 'Guarulhos', 'state' => 'SP',
                'latitude' => -23.435600, 'longitude' => -46.473100, 'pickup_cutoff' => '16:00',
            ],
            [
                'code' => 'BHZ1', 'name' => 'CD Contagem', 'city' => 'Contagem', 'state' => 'MG',
                'latitude' => -19.932100, 'longitude' => -44.053900, 'pickup_cutoff' => '15:00',
            ],
        ], ['code'], ['name', 'city', 'state', 'latitude', 'longitude', 'pickup_cutoff']);
    }
}
