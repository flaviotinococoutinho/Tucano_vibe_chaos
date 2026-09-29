<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\IntegrationTestCase;

#[Group('integration')]
final class StoresTest extends IntegrationTestCase
{
    private const array ARARA = ['slug' => 'arara', 'name' => 'Arara Livros', 'tagline' => 'Livros para quem constrói sistemas.', 'palette' => 'arara'];
    private const array BEMTEVI = ['slug' => 'bemtevi', 'name' => 'Bem-te-vi Eletrônicos', 'tagline' => 'Eletrônicos para a mesa de trabalho.', 'palette' => 'bemtevi'];
    private const array SABIA = ['slug' => 'sabia', 'name' => 'Sabiá Casa e Esporte', 'tagline' => 'Da cozinha ao treino, o que o dia pede.', 'palette' => 'sabia'];

    #[Test]
    public function it_lists_the_stores_of_the_platform(): void
    {
        $this->json('GET', '/v1/stores');

        $this->response->assertOk()->assertExactJson(['stores' => [self::ARARA, self::BEMTEVI, self::SABIA]]);
    }

    #[Test]
    public function the_stores_come_sorted_by_name_not_by_slug(): void
    {
        $this->insertStore(['slug' => 'urutau', 'name' => 'Acauã Ferramentas']);

        $this->json('GET', '/v1/stores');

        self::assertSame(['urutau', 'arara', 'bemtevi', 'sabia'], $this->response->json('stores.*.slug'));
    }

    #[Test]
    public function it_shows_one_store(): void
    {
        $this->json('GET', '/v1/stores/sabia');

        $this->response->assertOk()->assertExactJson(self::SABIA);
    }

    #[Test]
    public function an_unknown_store_is_a_404_problem(): void
    {
        $this->json('GET', '/v1/stores/tucano', [], ['X-Correlation-Id' => 'req-9#1']);

        $this->response->assertNotFound()
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertExactJson([
                'type' => 'about:blank',
                'title' => 'Not Found',
                'status' => 404,
                'detail' => 'Store tucano does not exist.',
                'instance' => '/v1/stores/tucano',
                'correlationId' => 'req-9#1',
            ]);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedSlugs(): iterable
    {
        yield 'the name of the store' => ['Sabia'];
        yield 'one letter' => ['s'];
        yield 'starting with a digit' => ['1sabia'];
        yield 'longer than 31 characters' => [str_repeat('a', 32)];
    }

    #[Test]
    #[DataProvider('malformedSlugs')]
    public function a_malformed_slug_stops_at_the_router(string $slug): void
    {
        $this->json('GET', '/v1/stores/' . $slug);

        $this->response->assertNotFound()->assertJsonPath('detail', 'Not Found');
    }
}
