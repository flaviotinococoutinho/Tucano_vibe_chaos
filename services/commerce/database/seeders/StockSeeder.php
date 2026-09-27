<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Starting stock per SKU and fulfillment center. Heavy items only ship from
 * Guarulhos, and LAB-CONSOLE-001 has just a handful of units on purpose: it is
 * the hot product of the overselling lab.
 */
final class StockSeeder extends Seeder
{
    private const array STOCK = [
        'BOOK-DDD-001' => ['GRU1' => 40, 'BHZ1' => 25],
        'BOOK-DDIA-001' => ['GRU1' => 35, 'BHZ1' => 20],
        'BOOK-REL-001' => ['GRU1' => 30, 'BHZ1' => 15],
        'BOOK-CLEAN-001' => ['GRU1' => 50, 'BHZ1' => 30],
        'BOOK-SRE-001' => ['GRU1' => 20, 'BHZ1' => 10],
        'ELEC-KBD-001' => ['GRU1' => 25, 'BHZ1' => 15],
        'ELEC-MOUSE-001' => ['GRU1' => 60, 'BHZ1' => 40],
        'ELEC-MON-027' => ['GRU1' => 12],
        'ELEC-HEAD-001' => ['GRU1' => 30, 'BHZ1' => 20],
        'HOME-COFFEE-001' => ['GRU1' => 15, 'BHZ1' => 8],
        'HOME-CHAIR-001' => ['GRU1' => 10],
        'HOME-MUG-001' => ['GRU1' => 120, 'BHZ1' => 80],
        'SPORT-BIKE-029' => ['GRU1' => 6],
        'SPORT-YOGA-001' => ['GRU1' => 40, 'BHZ1' => 25],
        'SPORT-BOTTLE-001' => ['GRU1' => 70, 'BHZ1' => 50],
        'LAB-CONSOLE-001' => ['GRU1' => 5],
    ];

    public function run(): void
    {
        $rows = [];
        foreach (self::STOCK as $sku => $centers) {
            foreach ($centers as $center => $onHand) {
                $rows[] = ['sku' => $sku, 'fulfillment_center' => $center, 'on_hand' => $onHand, 'reserved' => 0];
            }
        }

        // Insert only what is missing: seeding again must never reset stock that orders already moved.
        DB::table('stock_items')->insertOrIgnore($rows);
    }
}
