<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;

#[Group('integration')]
final class ListStoreProductsTest extends IntegrationTestCase
{
    #[Test]
    public function it_lists_only_the_active_products_of_the_store_by_name(): void
    {
        $this->json('GET', '/v1/stores/sabia/products');

        $this->response->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('perPage', 20)
            ->assertJsonPath('total', 6);
        // The draft lamp stays out, and so does everything the other stores sell.
        self::assertSame([
            'Bicicleta aro 29',
            'Cadeira de escritório',
            'Cafeteira elétrica',
            'Caneca de cerâmica',
            'Garrafa térmica',
            'Tapete de yoga',
        ], $this->response->json('data.*.name'));
    }

    /** @return iterable<string, array{string, int, list<string>}> */
    public static function stores(): iterable
    {
        yield 'arara sells the books' => ['arara', 5, ['books']];
        yield 'bemtevi sells the electronics, without the discontinued MP3 player' => ['bemtevi', 5, ['electronics']];
        yield 'sabia sells home and sports' => ['sabia', 6, ['home', 'sports']];
    }

    /** @param list<string> $categories */
    #[Test]
    #[DataProvider('stores')]
    public function each_store_sees_only_what_it_sells(string $store, int $total, array $categories): void
    {
        $this->json('GET', "/v1/stores/{$store}/products");

        $this->response->assertOk()->assertJsonPath('total', $total)->assertJsonCount($total, 'data');
        self::assertSame([$store], array_values(array_unique((array) $this->response->json('data.*.store'))));
        $seen = array_values(array_unique((array) $this->response->json('data.*.category')));
        sort($seen);
        self::assertSame($categories, $seen);
    }

    #[Test]
    public function the_body_is_the_same_as_the_platform_list(): void
    {
        $this->json('GET', '/v1/products?category=books');
        $platform = $this->response->json();

        $this->json('GET', '/v1/stores/arara/products');

        self::assertSame($platform, $this->response->json());
    }

    #[Test]
    public function it_filters_by_category_inside_the_store(): void
    {
        $this->json('GET', '/v1/stores/sabia/products?category=sports');

        $this->response->assertOk()->assertJsonPath('total', 3);
        self::assertSame(['Bicicleta aro 29', 'Garrafa térmica', 'Tapete de yoga'], $this->response->json('data.*.name'));
    }

    #[Test]
    public function a_category_the_store_does_not_sell_is_an_empty_page(): void
    {
        $this->json('GET', '/v1/stores/arara/products?category=home');

        $this->response->assertOk()->assertJsonPath('total', 0)->assertJsonPath('data', []);
    }

    #[Test]
    public function it_pages_twenty_products_at_a_time(): void
    {
        foreach (range(1, 16) as $number) {
            $this->insertProduct(['sku' => sprintf('BOOK-PAGE-%03d', $number), 'name' => sprintf('Zeta %02d', $number)]);
        }
        // The same product in a neighbor store never takes a place in this list.
        $this->insertProduct(['sku' => 'HOME-PAGE-001', 'name' => 'Aardvark', 'store_id' => $this->storeId('sabia')]);

        $this->json('GET', '/v1/stores/arara/products?page=1');
        $this->response->assertJsonPath('total', 21)->assertJsonCount(20, 'data');
        $first = (array) $this->response->json('data.*.sku');

        $this->json('GET', '/v1/stores/arara/products?page=2');
        $this->response->assertJsonPath('page', 2)->assertJsonPath('total', 21);

        self::assertSame(['BOOK-PAGE-016'], $this->response->json('data.*.sku'));
        self::assertNotContains('HOME-PAGE-001', $first);
    }

    #[Test]
    public function an_unknown_store_is_not_found(): void
    {
        $this->json('GET', '/v1/stores/tucano/products');

        $this->response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Store tucano does not exist.');
    }

    #[Test]
    public function an_unknown_category_is_rejected_on_its_field(): void
    {
        $this->json('GET', '/v1/stores/arara/products?category=toys');

        $this->response->assertUnprocessable()->assertJsonPath('errors', ['category' => ['Category "toys" does not exist.']]);
    }

    #[Test]
    public function the_page_must_be_a_positive_number(): void
    {
        $this->json('GET', '/v1/stores/arara/products?page=0');

        $this->response->assertUnprocessable()->assertJsonPath('errors.page.0', 'The page must be at least 1.');
    }
}
