<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;
use Tucano\SharedKernel\Time\Clock;
use Tucano\SharedKernel\Time\FrozenClock;

#[Group('integration')]
final class CreateProductTest extends IntegrationTestCase
{
    private const array REFACTORING = [
        'sku' => 'BOOK-REF-001',
        'name' => 'Refactoring',
        'store' => 'arara',
        'category' => 'books',
        'price' => ['amount' => 15990, 'currency' => 'BRL'],
        'weightGrams' => 900,
        'dimensions' => ['lengthMm' => 235, 'widthMm' => 180, 'heightMm' => 30],
    ];

    #[Test]
    public function it_creates_a_draft_in_its_store(): void
    {
        $this->app->instance(Clock::class, new FrozenClock('2026-09-28T10:15:30.123456Z'));

        $this->json('POST', '/v1/products', self::REFACTORING);

        $this->response->assertCreated()
            ->assertHeader('Location', '/v1/products/BOOK-REF-001')
            ->assertHeader('ETag', '"1"')
            ->assertJsonPath('sku', 'BOOK-REF-001')
            ->assertJsonPath('status', 'draft')
            ->assertJsonPath('store', 'arara')
            ->assertJsonPath('category', 'books')
            ->assertJsonPath('price', ['amount' => 15990, 'currency' => 'BRL'])
            ->assertJsonPath('dimensions', ['lengthMm' => 235, 'widthMm' => 180, 'heightMm' => 30])
            ->assertJsonPath('version', 1)
            ->assertJsonPath('updatedAt', '2026-09-28T10:15:30.123Z');
        $row = $this->productRow('BOOK-REF-001');
        self::assertSame($this->response->json('id'), $row->id_text);
        self::assertSame('draft', $row->status);
        self::assertSame($this->storeId('arara'), $row->store_id);
        self::assertSame('2026-09-28 10:15:30.123456', $row->updated_at);
    }

    #[Test]
    public function once_on_sale_only_its_store_sells_it(): void
    {
        $this->json('POST', '/v1/products', ['sku' => 'HOME-POT-001', 'name' => 'Panela de ferro', 'store' => 'sabia', 'category' => 'home'] + self::REFACTORING);
        $this->json('POST', '/v1/products/HOME-POT-001/activate');

        $this->json('GET', '/v1/stores/sabia/products/HOME-POT-001');
        $this->response->assertOk()->assertJsonPath('store', 'sabia');
        $this->json('GET', '/v1/stores/arara/products/HOME-POT-001');
        $this->response->assertNotFound();
    }

    #[Test]
    public function a_new_draft_is_not_published(): void
    {
        $this->json('POST', '/v1/products', self::REFACTORING);

        self::assertSame([], $this->kafka->delivered());
    }

    #[Test]
    public function a_sku_is_created_once(): void
    {
        $this->json('POST', '/v1/products', ['sku' => 'BOOK-DDD-001'] + self::REFACTORING);

        $this->response->assertConflict()->assertJsonPath('detail', 'Product BOOK-DDD-001 already exists.');
    }

    #[Test]
    public function the_sku_stays_unique_across_the_stores(): void
    {
        $this->json('POST', '/v1/products', ['sku' => 'HOME-MUG-001', 'store' => 'arara'] + self::REFACTORING);

        $this->response->assertConflict()->assertJsonPath('detail', 'Product HOME-MUG-001 already exists.');
        self::assertSame('sabia', $this->storeOf('HOME-MUG-001'));
    }

    #[Test]
    public function a_product_without_a_store_is_refused_on_the_field(): void
    {
        $withoutStore = self::REFACTORING;
        unset($withoutStore['store']);

        $this->json('POST', '/v1/products', $withoutStore);

        $this->response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('errors', ['store' => ['The store field is required.']]);
        self::assertNull($this->database()->table('products')->where('sku', 'BOOK-REF-001')->first());
    }

    #[Test]
    public function the_store_must_exist(): void
    {
        $this->json('POST', '/v1/products', ['store' => 'tucano'] + self::REFACTORING);

        $this->response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Store "tucano" does not exist.')
            ->assertJsonPath('errors', ['store' => ['Store "tucano" does not exist.']]);
        self::assertNull($this->database()->table('products')->where('sku', 'BOOK-REF-001')->first());
    }

    #[Test]
    public function the_category_must_exist(): void
    {
        $this->json('POST', '/v1/products', ['category' => 'toys'] + self::REFACTORING);

        $this->response->assertUnprocessable()
            ->assertJsonPath('detail', 'Category "toys" does not exist.')
            ->assertJsonPath('errors', ['category' => ['Category "toys" does not exist.']]);
    }

    #[Test]
    public function an_empty_body_lists_every_missing_field(): void
    {
        $this->json('POST', '/v1/products', []);

        $this->response->assertUnprocessable()->assertHeader('Content-Type', 'application/problem+json');
        self::assertSame(
            ['sku', 'name', 'store', 'category', 'price', 'weightGrams', 'dimensions'],
            array_keys((array) $this->response->json('errors')),
        );
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidFields(): iterable
    {
        yield 'lowercase sku' => ['sku', ['sku' => 'book-ref-001']];
        yield 'sku too long' => ['sku', ['sku' => 'BOOK-' . str_repeat('X', 30)]];
        yield 'blank name' => ['name', ['name' => '   ']];
        yield 'name too long' => ['name', ['name' => str_repeat('a', 161)]];
        yield 'store by its name' => ['store', ['store' => 'Arara Livros']];
        yield 'store with an accent' => ['store', ['store' => 'sabiá']];
        yield 'store starting with a digit' => ['store', ['store' => '1arara']];
        yield 'category outside the slug format' => ['category', ['category' => 'Books']];
        yield 'negative price' => ['price.amount', ['price' => ['amount' => -1, 'currency' => 'BRL']]];
        yield 'price in reais with cents' => ['price.amount', ['price' => ['amount' => 159.9, 'currency' => 'BRL']]];
        yield 'currency outside ISO 4217' => ['price.currency', ['price' => ['amount' => 15990, 'currency' => 'real']]];
        yield 'price with extra keys' => ['price', ['price' => ['amount' => 15990, 'currency' => 'BRL', 'tax' => 0]]];
        yield 'weightless product' => ['weightGrams', ['weightGrams' => 0]];
        yield 'weight beyond INT UNSIGNED' => ['weightGrams', ['weightGrams' => 4_294_967_296]];
        yield 'missing height' => ['dimensions.heightMm', ['dimensions' => ['lengthMm' => 235, 'widthMm' => 180]]];
        yield 'negative width' => ['dimensions.widthMm', ['dimensions' => ['lengthMm' => 235, 'widthMm' => -180, 'heightMm' => 30]]];
    }

    /** @param array<string, mixed> $override */
    #[Test]
    #[DataProvider('invalidFields')]
    public function every_field_is_validated(string $field, array $override): void
    {
        $this->json('POST', '/v1/products', $override + self::REFACTORING);

        $this->response->assertUnprocessable();
        self::assertSame([$field], array_keys((array) $this->response->json('errors')));
        self::assertNull($this->database()->table('products')->where('sku', 'BOOK-REF-001')->first());
    }
}
