<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Tests\Privacy;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tucano\SharedKernel\Privacy\DataCategory;
use Tucano\SharedKernel\Privacy\Sensitive;

#[CoversClass(Sensitive::class)]
#[CoversClass(DataCategory::class)]
final class SensitiveTest extends TestCase
{
    /** @return iterable<string, array{DataCategory, string, string}> */
    public static function masks(): iterable
    {
        yield 'a name shows its initials' => [DataCategory::PersonName, 'Ana Maria  Souza', 'A*** M*** S***'];
        yield 'an accented name keeps its letter' => [DataCategory::PersonName, 'Érica Brandão', 'É*** B***'];
        yield 'an e-mail keeps its domain, for support' => [DataCategory::Email, 'ana.souza@example.com', 'a***@example.com'];
        yield 'something that is not an e-mail shows nothing' => [DataCategory::Email, 'ana', '***'];
        yield 'a document shows its last two digits' => [DataCategory::Document, '123.456.789-09', '***09'];
        yield 'a card token keeps its prefix' => [DataCategory::CardToken, 'tok_visa', 'tok_***'];
        yield 'a card number sent as a token shows nothing' => [DataCategory::CardToken, '4111111111111111', '***'];
    }

    #[Test]
    #[DataProvider('masks')]
    public function each_category_masks_in_its_own_way(DataCategory $category, string $value, string $mask): void
    {
        self::assertSame($mask, (string) Sensitive::of($value, $category));
    }

    #[Test]
    public function the_value_comes_out_only_when_asked_for(): void
    {
        $email = Sensitive::of('ana@example.com', DataCategory::Email);

        self::assertSame('ana@example.com', $email->reveal());
        self::assertSame('Sent to a***@example.com', sprintf('Sent to %s', $email));
        self::assertSame('{"to":"a***@example.com"}', json_encode(['to' => $email], JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('ana@', print_r($email, true));
    }

    #[Test]
    public function a_dump_shows_the_category_and_the_mask(): void
    {
        ob_start();
        var_dump(Sensitive::of('123.456.789-09', DataCategory::Document));
        $dump = (string) ob_get_clean();

        self::assertStringContainsString('"***09"', $dump);
        self::assertStringNotContainsString('123.456', $dump);
    }

    #[Test]
    public function it_refuses_to_be_serialized_into_a_cache_or_a_queue(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A card_token is sensitive (PCI DSS)');

        serialize(Sensitive::of('tok_visa', DataCategory::CardToken));
    }

    #[Test]
    public function each_category_answers_to_its_rule(): void
    {
        self::assertSame(
            ['person_name' => 'LGPD', 'email' => 'LGPD', 'document' => 'LGPD', 'card_token' => 'PCI DSS'],
            array_combine(
                array_map(static fn(DataCategory $category): string => $category->value, DataCategory::cases()),
                array_map(static fn(DataCategory $category): string => $category->regime(), DataCategory::cases()),
            ),
        );
    }
}
