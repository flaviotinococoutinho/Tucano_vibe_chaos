<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Exceptions\DuplicateSku;
use App\Models\Dimensions;
use App\Models\Product;
use App\Models\ProductId;
use App\Models\ProductPage;
use App\Models\ProductStatus;
use DateTimeImmutable;
use DateTimeZone;
use Generator;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Ramsey\Uuid\Uuid;
use stdClass;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/** Products in MySQL: the id is BINARY(16) in the table and text everywhere else. */
final readonly class ProductRepository
{
    private const int DUPLICATE_ENTRY = 1062;
    private const string DATETIME_FORMAT = 'Y-m-d H:i:s.u';
    private const string COLUMNS = 'BIN_TO_UUID(p.id) AS id, p.sku, p.name, p.status, c.slug AS category, '
        . 'p.price_cents, p.currency, p.weight_grams, p.length_mm, p.width_mm, p.height_mm, p.version, p.updated_at';

    public function __construct(private DatabaseManager $database, private int $pageSize) {}

    public function findBySku(string $sku): ?Product
    {
        $row = $this->products()->where('p.sku', $sku)->first();

        return $row instanceof stdClass ? self::product($row) : null;
    }

    public function activePage(?string $category, int $page): ProductPage
    {
        $query = $this->products()->where('p.status', ProductStatus::Active->value);
        if ($category !== null) {
            $query->where('c.slug', $category);
        }
        // The id breaks ties between equal names, so a product never shows up on two pages.
        $rows = $query->clone()->orderBy('p.name')->orderBy('p.id')->forPage($page, $this->pageSize)->get();

        return new ProductPage(self::productsFrom($rows->all()), $page, $this->pageSize, $query->count());
    }

    /** @throws DuplicateSku */
    public function add(Product $product): void
    {
        try {
            $this->connection()->insert(<<<'SQL'
                INSERT INTO products (id, sku, name, category_id, status, price_cents, currency,
                                      weight_grams, length_mm, width_mm, height_mm, version, created_at, updated_at)
                VALUES (UUID_TO_BIN(?), ?, ?, (SELECT id FROM categories WHERE slug = ?), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                SQL, [
                $product->id->toString(),
                $product->sku,
                $product->name,
                $product->category,
                $product->status->value,
                $product->price->cents(),
                $product->price->currency()->code(),
                $product->weightGrams,
                $product->dimensions->lengthMm,
                $product->dimensions->widthMm,
                $product->dimensions->heightMm,
                $product->version,
                self::datetime($product->updatedAt),
                self::datetime($product->updatedAt),
            ]);
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) === self::DUPLICATE_ENTRY) {
                throw DuplicateSku::of($product->sku);
            }

            throw $error;
        }
    }

    /**
     * Writes the new state only while the row still holds the version that was read
     * (optimistic concurrency). False means someone changed the product in between.
     */
    public function update(Product $product, int $readVersion): bool
    {
        $changed = $this->connection()->update(<<<'SQL'
            UPDATE products
            SET name = ?, category_id = (SELECT id FROM categories WHERE slug = ?), status = ?,
                price_cents = ?, currency = ?, weight_grams = ?, length_mm = ?, width_mm = ?, height_mm = ?,
                version = ?, updated_at = ?
            WHERE id = UUID_TO_BIN(?) AND version = ?
            SQL, [
            $product->name,
            $product->category,
            $product->status->value,
            $product->price->cents(),
            $product->price->currency()->code(),
            $product->weightGrams,
            $product->dimensions->lengthMm,
            $product->dimensions->widthMm,
            $product->dimensions->heightMm,
            $product->version,
            self::datetime($product->updatedAt),
            $product->id->toString(),
            $readVersion,
        ]);

        return $changed === 1;
    }

    /**
     * Active and discontinued products in id order, a batch at a time, so a large
     * catalog never sits in memory at once.
     *
     * @return Generator<int, non-empty-list<Product>>
     */
    public function published(?string $sku, int $batchSize): Generator
    {
        $after = Uuid::NIL;
        do {
            $query = $this->products()
                ->where('p.status', '!=', ProductStatus::Draft->value)
                ->whereRaw('p.id > UUID_TO_BIN(?)', [$after])
                ->orderBy('p.id')
                ->limit($batchSize);
            if ($sku !== null) {
                $query->where('p.sku', $sku);
            }
            $batch = self::productsFrom($query->get()->all());
            if ($batch === []) {
                return;
            }

            yield $batch;
            $after = $batch[count($batch) - 1]->id->toString();
        } while (count($batch) === $batchSize);
    }

    private function products(): Builder
    {
        return $this->connection()->table('products AS p')
            ->join('categories AS c', 'c.id', '=', 'p.category_id')
            ->selectRaw(self::COLUMNS);
    }

    private function connection(): ConnectionInterface
    {
        return $this->database->connection();
    }

    /**
     * @param array<int, stdClass> $rows
     * @return list<Product>
     */
    private static function productsFrom(array $rows): array
    {
        return array_values(array_map(self::product(...), $rows));
    }

    private static function product(stdClass $row): Product
    {
        return new Product(
            ProductId::fromString((string) $row->id),
            (string) $row->sku,
            (string) $row->name,
            ProductStatus::from((string) $row->status),
            (string) $row->category,
            Money::of((int) $row->price_cents, Currency::fromCode((string) $row->currency)),
            (int) $row->weight_grams,
            Dimensions::ofMillimetres((int) $row->length_mm, (int) $row->width_mm, (int) $row->height_mm),
            (int) $row->version,
            new DateTimeImmutable((string) $row->updated_at, new DateTimeZone('UTC')),
        );
    }

    private static function datetime(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME_FORMAT);
    }
}
