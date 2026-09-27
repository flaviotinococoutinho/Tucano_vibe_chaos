<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;

#[Group('integration')]
final class ChangeProductTest extends IntegrationTestCase
{
    #[Test]
    public function a_partial_update_changes_only_what_was_sent(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['price' => ['amount' => 17990, 'currency' => 'BRL']]);

        $this->response->assertOk()
            ->assertHeader('ETag', '"2"')
            ->assertJsonPath('price', ['amount' => 17990, 'currency' => 'BRL'])
            ->assertJsonPath('name', 'Domain-Driven Design')
            ->assertJsonPath('weightGrams', 1100)
            ->assertJsonPath('version', 2);
        $row = $this->productRow('BOOK-DDD-001');
        self::assertSame(17990, (int) $row->price_cents);
        self::assertSame(2, (int) $row->version);
    }

    #[Test]
    public function every_editable_field_changes(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', [
            'name' => 'Domain-Driven Design (capa dura)',
            'category' => 'home',
            'weightGrams' => 1300,
            'dimensions' => ['lengthMm' => 245, 'widthMm' => 175, 'heightMm' => 45],
        ]);

        $this->response->assertOk()
            ->assertJsonPath('name', 'Domain-Driven Design (capa dura)')
            ->assertJsonPath('category', 'home')
            ->assertJsonPath('weightGrams', 1300)
            ->assertJsonPath('dimensions', ['lengthMm' => 245, 'widthMm' => 175, 'heightMm' => 45]);
        $this->json('GET', '/v1/products?category=home');
        self::assertContains('BOOK-DDD-001', (array) $this->response->json('data.*.sku'));
    }

    #[Test]
    public function a_draft_can_be_edited(): void
    {
        $this->json('PATCH', '/v1/products/HOME-LAMP-001', ['name' => 'Luminária articulada']);

        $this->response->assertOk()->assertJsonPath('status', 'draft')->assertJsonPath('version', 2);
    }

    #[Test]
    public function a_change_that_changes_nothing_keeps_the_version(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['name' => 'Domain-Driven Design']);
        $this->response->assertOk()->assertHeader('ETag', '"1"');

        $this->json('PATCH', '/v1/products/BOOK-DDD-001', []);
        $this->response->assertOk()->assertHeader('ETag', '"1"');

        self::assertSame(1, (int) $this->productRow('BOOK-DDD-001')->version);
        self::assertSame([], $this->kafka->delivered());
    }

    #[Test]
    public function if_match_with_the_current_version_goes_through(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['name' => 'DDD'], ['If-Match' => '"1"']);
        $this->response->assertOk()->assertHeader('ETag', '"2"');

        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['name' => 'Domain-Driven Design'], ['If-Match' => '"2"']);
        $this->response->assertOk()->assertHeader('ETag', '"3"');
    }

    #[Test]
    public function a_stale_if_match_is_a_conflict(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['name' => 'DDD']);

        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['name' => 'Blue book'], ['If-Match' => '"1"']);

        $this->response->assertConflict()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Product BOOK-DDD-001 is at version 2, not 1. Read it again before changing it.');
        self::assertSame('DDD', $this->productRow('BOOK-DDD-001')->name);
    }

    #[Test]
    public function if_match_any_skips_the_check(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['name' => 'DDD'], ['If-Match' => '*']);

        $this->response->assertOk()->assertHeader('ETag', '"2"');
    }

    /** @return iterable<string, array{string}> */
    public static function malformedIfMatch(): iterable
    {
        yield 'without quotes' => ['1'];
        yield 'a weak tag, which never matches a strong comparison' => ['W/"1"'];
        yield 'not a version' => ['"one"'];
    }

    #[Test]
    #[DataProvider('malformedIfMatch')]
    public function a_malformed_if_match_is_a_bad_request(string $ifMatch): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['name' => 'DDD'], ['If-Match' => $ifMatch]);

        $this->response->assertBadRequest()
            ->assertJsonPath('detail', 'If-Match takes the ETag of the product: its version in quotes, like "3".');
    }

    #[Test]
    public function an_unknown_product_is_not_found(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-NONE-001', ['name' => 'Nothing']);

        $this->response->assertNotFound()->assertJsonPath('detail', 'Product BOOK-NONE-001 does not exist.');
    }

    #[Test]
    public function the_new_category_must_exist(): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', ['category' => 'toys']);

        $this->response->assertUnprocessable()->assertJsonPath('detail', 'Category "toys" does not exist.');
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function invalidChanges(): iterable
    {
        yield 'blank name' => ['name', ['name' => '']];
        yield 'null price' => ['price', ['price' => null]];
        yield 'price without currency' => ['price.currency', ['price' => ['amount' => 100]]];
        yield 'weight as text' => ['weightGrams', ['weightGrams' => 'heavy']];
        yield 'zero length' => ['dimensions.lengthMm', ['dimensions' => ['lengthMm' => 0, 'widthMm' => 1, 'heightMm' => 1]]];
    }

    /** @param array<string, mixed> $changes */
    #[Test]
    #[DataProvider('invalidChanges')]
    public function every_change_is_validated(string $field, array $changes): void
    {
        $this->json('PATCH', '/v1/products/BOOK-DDD-001', $changes);

        $this->response->assertUnprocessable();
        self::assertSame([$field], array_keys((array) $this->response->json('errors')));
        self::assertSame(1, (int) $this->productRow('BOOK-DDD-001')->version);
    }
}
