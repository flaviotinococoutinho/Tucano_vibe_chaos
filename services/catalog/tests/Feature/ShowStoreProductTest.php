<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProductCache;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;

/** A store reads only its own products, and another store's product looks like no product at all. */
#[Group('integration')]
final class ShowStoreProductTest extends IntegrationTestCase
{
    #[Test]
    public function it_shows_a_product_of_the_store_as_the_platform_does(): void
    {
        $this->json('GET', '/v1/products/HOME-MUG-001');
        $platform = $this->response->json();

        $this->json('GET', '/v1/stores/sabia/products/HOME-MUG-001');

        $this->response->assertOk()->assertHeader('ETag', '"1"')->assertJsonPath('store', 'sabia');
        self::assertSame($platform, $this->response->json());
    }

    #[Test]
    public function a_discontinued_product_keeps_its_page_in_its_store(): void
    {
        $this->json('GET', '/v1/stores/bemtevi/products/ELEC-MP3-001');

        $this->response->assertOk()->assertJsonPath('status', 'discontinued');
    }

    /** @return iterable<string, array{string, string}> */
    public static function productsTheStoreDoesNotShow(): iterable
    {
        yield 'a product of another store' => ['arara', 'HOME-MUG-001'];
        yield 'a sku nobody sells' => ['arara', 'BOOK-NONE-001'];
        yield 'a draft of the store' => ['sabia', 'HOME-LAMP-001'];
        yield 'any sku under a store that does not exist' => ['tucano', 'HOME-MUG-001'];
    }

    #[Test]
    #[DataProvider('productsTheStoreDoesNotShow')]
    public function whatever_the_store_does_not_sell_is_the_same_404(string $store, string $sku): void
    {
        $this->json('GET', "/v1/stores/{$store}/products/{$sku}", [], ['X-Correlation-Id' => 'req-3#1']);

        $this->response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => 'about:blank',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => "Product {$sku} does not exist.",
                'instance' => "/v1/stores/{$store}/products/{$sku}",
                'correlationId' => 'req-3#1',
            ]);
    }

    #[Test]
    public function a_product_of_another_store_answers_like_the_platform_answers_an_unknown_sku(): void
    {
        $this->json('GET', '/v1/products/BOOK-NONE-001');
        $unknown = $this->problem();

        $this->json('GET', '/v1/stores/arara/products/HOME-MUG-001');

        self::assertSame($unknown, str_replace('HOME-MUG-001', 'BOOK-NONE-001', $this->problem()));
    }

    #[Test]
    public function a_malformed_sku_stops_at_the_router(): void
    {
        $this->json('GET', '/v1/stores/sabia/products/home-mug-001');

        $this->response->assertNotFound()->assertJsonPath('detail', 'Not Found');
        self::assertFalse($this->app->make('cache.store')->has(ProductCache::key('home-mug-001')));
    }

    #[Test]
    public function a_malformed_store_stops_at_the_router(): void
    {
        $this->json('GET', '/v1/stores/Sabia/products/HOME-MUG-001');

        $this->response->assertNotFound()->assertJsonPath('detail', 'Not Found');
        self::assertFalse($this->app->make('cache.store')->has(ProductCache::key('HOME-MUG-001')));
    }

    /** The problem as the client reads it, without what changes from one request to the next. */
    private function problem(): string
    {
        $problem = (array) $this->response->json();
        unset($problem['instance'], $problem['correlationId']);

        return (string) json_encode($problem);
    }
}
