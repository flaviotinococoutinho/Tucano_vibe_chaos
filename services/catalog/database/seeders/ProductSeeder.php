<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * The active SKUs match the stock that commerce seeds. A discontinued product
 * and a draft cover the states checkout must refuse.
 */
final class ProductSeeder extends Seeder
{
    /** sku => [name, category, price in cents, weight in grams, length, width and height in mm, status] */
    private const array PRODUCTS = [
        'BOOK-DDD-001' => ['Domain-Driven Design', 'books', 18990, 1100, 240, 170, 40, 'active'],
        'BOOK-DDIA-001' => ['Designing Data-Intensive Applications', 'books', 32990, 1000, 235, 180, 35, 'active'],
        'BOOK-REL-001' => ['Release It!', 'books', 21990, 700, 230, 190, 25, 'active'],
        'BOOK-CLEAN-001' => ['Clean Architecture', 'books', 15990, 650, 230, 150, 25, 'active'],
        'BOOK-SRE-001' => ['Site Reliability Engineering', 'books', 27990, 1200, 235, 180, 40, 'active'],
        'ELEC-KBD-001' => ['Teclado mecânico', 'electronics', 49990, 1100, 440, 140, 40, 'active'],
        'ELEC-MOUSE-001' => ['Mouse sem fio', 'electronics', 12990, 150, 120, 70, 40, 'active'],
        'ELEC-MON-027' => ['Monitor de 27 polegadas', 'electronics', 189990, 6500, 620, 200, 450, 'active'],
        'ELEC-HEAD-001' => ['Fone com cancelamento de ruído', 'electronics', 99990, 400, 200, 180, 90, 'active'],
        'ELEC-MP3-001' => ['Tocador de MP3', 'electronics', 19990, 100, 100, 60, 20, 'discontinued'],
        'HOME-COFFEE-001' => ['Cafeteira elétrica', 'home', 34990, 2500, 300, 250, 350, 'active'],
        'HOME-CHAIR-001' => ['Cadeira de escritório', 'home', 129990, 22000, 700, 650, 400, 'active'],
        'HOME-MUG-001' => ['Caneca de cerâmica', 'home', 4990, 400, 120, 100, 100, 'active'],
        'HOME-LAMP-001' => ['Luminária de mesa', 'home', 15990, 900, 400, 200, 200, 'draft'],
        'SPORT-BIKE-029' => ['Bicicleta aro 29', 'sports', 249990, 16000, 1500, 250, 800, 'active'],
        'SPORT-YOGA-001' => ['Tapete de yoga', 'sports', 8990, 1200, 620, 150, 150, 'active'],
        'SPORT-BOTTLE-001' => ['Garrafa térmica', 'sports', 6990, 350, 260, 80, 80, 'active'],
        'LAB-CONSOLE-001' => ['Console portátil edição limitada', 'electronics', 299990, 900, 300, 150, 100, 'active'],
    ];

    public function run(): void
    {
        $categories = DB::table('categories')->pluck('id', 'slug');

        $rows = [];
        foreach (self::PRODUCTS as $sku => [$name, $category, $price, $weight, $length, $width, $height, $status]) {
            $rows[] = [
                'id' => Uuid::uuid7()->getBytes(),
                'sku' => $sku,
                'name' => $name,
                'category_id' => $categories[$category],
                'status' => $status,
                'price_cents' => $price,
                'currency' => 'BRL',
                'weight_grams' => $weight,
                'length_mm' => $length,
                'width_mm' => $width,
                'height_mm' => $height,
            ];
        }

        // A known SKU keeps the price and state it has now. Not insertOrIgnore: in MySQL,
        // INSERT IGNORE also turns CHECK and type errors into warnings and moves on.
        DB::table('products')->upsert($rows, ['sku'], ['sku']);
    }
}
