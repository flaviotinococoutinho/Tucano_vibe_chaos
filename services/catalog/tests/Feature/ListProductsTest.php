<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;

#[Group('integration')]
final class ListProductsTest extends IntegrationTestCase
{
    #[Test]
    public function it_lists_only_active_products_by_name(): void
    {
        $this->json('GET', '/v1/products');

        $this->response->assertOk()
            ->assertJsonPath('page', 1)
            ->assertJsonPath('perPage', 20)
            ->assertJsonPath('total', 16);
        // The draft (Luminária de mesa) and the discontinued MP3 player stay out.
        self::assertSame([
            'Bicicleta aro 29',
            'Cadeira de escritório',
            'Cafeteira elétrica',
            'Caneca de cerâmica',
            'Clean Architecture',
            'Console portátil edição limitada',
            'Designing Data-Intensive Applications',
            'Domain-Driven Design',
            'Fone com cancelamento de ruído',
            'Garrafa térmica',
            'Monitor de 27 polegadas',
            'Mouse sem fio',
            'Release It!',
            'Site Reliability Engineering',
            'Tapete de yoga',
            'Teclado mecânico',
        ], $this->response->json('data.*.name'));
    }

    #[Test]
    public function it_filters_by_category(): void
    {
        $this->json('GET', '/v1/products?category=books');

        $this->response->assertOk()->assertJsonPath('total', 5);
        self::assertSame([
            'Clean Architecture',
            'Designing Data-Intensive Applications',
            'Domain-Driven Design',
            'Release It!',
            'Site Reliability Engineering',
        ], $this->response->json('data.*.name'));
    }

    #[Test]
    public function it_pages_twenty_products_at_a_time(): void
    {
        foreach (range(1, 6) as $number) {
            $this->insertProduct(['sku' => sprintf('BOOK-PAGE-%03d', $number), 'name' => "Zeta {$number}"]);
        }

        $this->json('GET', '/v1/products?page=1');
        $this->response->assertJsonPath('total', 22)->assertJsonCount(20, 'data');
        $first = (array) $this->response->json('data.*.sku');

        $this->json('GET', '/v1/products?page=2');
        $this->response->assertJsonPath('page', 2)->assertJsonPath('total', 22);
        $second = (array) $this->response->json('data.*.sku');

        self::assertSame(['BOOK-PAGE-005', 'BOOK-PAGE-006'], $second);
        self::assertSame([], array_intersect($first, $second));
    }

    #[Test]
    public function a_page_past_the_end_is_empty(): void
    {
        $this->json('GET', '/v1/products?page=3');

        $this->response->assertOk()->assertJsonPath('total', 16)->assertJsonPath('data', []);
    }

    #[Test]
    public function an_unknown_category_is_rejected(): void
    {
        $this->json('GET', '/v1/products?category=toys');

        $this->response->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('detail', 'Category "toys" does not exist.');
    }

    #[Test]
    public function the_page_must_be_a_positive_number(): void
    {
        $this->json('GET', '/v1/products?page=0');
        $this->response->assertUnprocessable()->assertJsonPath('errors.page.0', 'The page must be at least 1.');

        $this->json('GET', '/v1/products?page=last');
        $this->response->assertUnprocessable()->assertJsonPath('errors.page.0', 'The page must be an integer.');

        $this->json('GET', '/v1/products?page=');
        $this->response->assertUnprocessable()->assertJsonPath('errors.page.0', 'The page field is required.');
    }
}
