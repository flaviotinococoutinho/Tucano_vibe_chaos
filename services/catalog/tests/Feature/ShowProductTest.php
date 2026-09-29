<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProductCache;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;

#[Group('integration')]
final class ShowProductTest extends IntegrationTestCase
{
    #[Test]
    public function it_shows_a_product_with_its_version_as_the_etag(): void
    {
        $row = $this->productRow('BOOK-DDD-001');
        $updatedAt = new DateTimeImmutable((string) $row->updated_at, new DateTimeZone('UTC'));

        $this->json('GET', '/v1/products/BOOK-DDD-001');

        $this->response->assertOk()
            ->assertHeader('ETag', '"1"')
            ->assertExactJson([
                'id' => $row->id_text,
                'sku' => 'BOOK-DDD-001',
                'name' => 'Domain-Driven Design',
                'status' => 'active',
                'store' => 'arara',
                'category' => 'books',
                'price' => ['amount' => 18990, 'currency' => 'BRL'],
                'weightGrams' => 1100,
                'dimensions' => ['lengthMm' => 240, 'widthMm' => 170, 'heightMm' => 40],
                'version' => 1,
                'updatedAt' => $updatedAt->format('Y-m-d\TH:i:s.v\Z'),
            ]);
    }

    #[Test]
    public function a_discontinued_product_is_still_shown(): void
    {
        $this->json('GET', '/v1/products/ELEC-MP3-001');

        $this->response->assertOk()->assertJsonPath('status', 'discontinued');
    }

    #[Test]
    public function a_draft_does_not_exist_outside_the_catalog(): void
    {
        $this->json('GET', '/v1/products/HOME-LAMP-001');

        $this->response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Product HOME-LAMP-001 does not exist.');
    }

    #[Test]
    public function an_unknown_sku_is_not_found(): void
    {
        $this->json('GET', '/v1/products/BOOK-NONE-001');

        $this->response->assertNotFound()->assertJsonPath('detail', 'Product BOOK-NONE-001 does not exist.');
    }

    #[Test]
    public function a_malformed_sku_stops_at_the_router(): void
    {
        $this->json('GET', '/v1/products/book-ddd-001');

        $this->response->assertNotFound()->assertJsonPath('detail', 'Not Found');
        self::assertFalse($this->app->make('cache.store')->has(ProductCache::key('book-ddd-001')));
    }
}
