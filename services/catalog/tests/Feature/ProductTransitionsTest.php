<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;

#[Group('integration')]
final class ProductTransitionsTest extends IntegrationTestCase
{
    #[Test]
    public function activating_a_draft_puts_it_on_sale(): void
    {
        $this->json('POST', '/v1/products/HOME-LAMP-001/activate');

        $this->response->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('status', 'active')
            ->assertJsonPath('version', 2);
        $this->json('GET', '/v1/products/HOME-LAMP-001');
        $this->response->assertOk()->assertJsonPath('status', 'active');
    }

    #[Test]
    public function a_discontinued_product_leaves_the_list_but_keeps_its_page(): void
    {
        $this->json('POST', '/v1/products/BOOK-DDD-001/discontinue');
        $this->response->assertOk()->assertJsonPath('status', 'discontinued')->assertJsonPath('version', 2);

        $this->json('GET', '/v1/products?category=books');
        $this->response->assertJsonPath('total', 4);
        $this->json('GET', '/v1/products/BOOK-DDD-001');
        $this->response->assertOk()->assertJsonPath('status', 'discontinued');
    }

    #[Test]
    public function a_discontinued_product_can_come_back(): void
    {
        $this->json('POST', '/v1/products/ELEC-MP3-001/activate');

        $this->response->assertOk()->assertJsonPath('status', 'active');
        self::assertSame('active', $this->productRow('ELEC-MP3-001')->status);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function forbiddenMoves(): iterable
    {
        yield 'a draft cannot be discontinued' => ['HOME-LAMP-001', 'discontinue', 'draft to discontinued'];
        yield 'an active product is already active' => ['BOOK-DDD-001', 'activate', 'active to active'];
        yield 'a discontinued product is already discontinued' => ['ELEC-MP3-001', 'discontinue', 'discontinued to discontinued'];
    }

    #[Test]
    #[DataProvider('forbiddenMoves')]
    public function a_move_outside_the_table_is_a_conflict(string $sku, string $action, string $move): void
    {
        $this->json('POST', "/v1/products/{$sku}/{$action}");

        $this->response->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', "Product {$sku} cannot move from {$move}.");
        self::assertSame(1, (int) $this->productRow($sku)->version);
    }

    #[Test]
    public function an_unknown_product_is_not_found(): void
    {
        $this->json('POST', '/v1/products/BOOK-NONE-001/activate');

        $this->response->assertNotFound()->assertJsonPath('detail', 'Product BOOK-NONE-001 does not exist.');
    }
}
