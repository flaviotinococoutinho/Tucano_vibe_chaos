<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Logging\LogContext;
use App\Models\ProductStatus;
use App\Services\ProductPublisher;
use App\Services\ProductSnapshot;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\Doubles\Products;
use Tucano\Messaging\Kafka\InMemoryProducer;
use Tucano\SharedKernel\Time\FrozenClock;

/** What the catalog publishes must match contracts/events, the files consumers test against too. */
final class ProductSnapshotContractTest extends TestCase
{
    private const string CONTRACTS = __DIR__ . '/../../../../contracts/events/';
    private const string ENVELOPE = 'cloudevent.schema.json';
    private const string SNAPSHOT = 'catalog.product.snapshot.schema.json';

    #[Test]
    public function published_events_carry_the_store_and_match_the_envelope_and_the_snapshot_contracts(): void
    {
        $kafka = new InMemoryProducer();
        $logContext = new LogContext();
        $logContext->add(LogContext::CORRELATION_ID, '4f1c2b7e-9d7a-4b8c-9e3f-1a2b3c4d5e6f#12');

        (new ProductPublisher($kafka, new FrozenClock(), $logContext))
            ->publish(Products::book(), Products::book(ProductStatus::Discontinued));

        self::assertCount(2, $kafka->delivered());
        foreach ($kafka->delivered() as $message) {
            $event = json_decode($message->payload, flags: JSON_THROW_ON_ERROR);
            self::assertMatchesContract(self::ENVELOPE, $event);
            self::assertMatchesContract(self::SNAPSHOT, $event->data);
            // The contract leaves the store optional for the facts from before the stores; the catalog always sends it.
            self::assertSame('arara', $event->data->store);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function storesOutsideTheSlugFormat(): iterable
    {
        yield 'the name of the store' => ['Arara Livros'];
        yield 'an accent' => ['sabiá'];
        yield 'one letter' => ['a'];
        yield 'starting with a digit' => ['1arara'];
    }

    #[Test]
    #[DataProvider('storesOutsideTheSlugFormat')]
    public function the_contract_takes_the_store_as_a_slug(string $store): void
    {
        $snapshot = ProductSnapshot::of(Products::book());
        $snapshot['store'] = $store;

        self::assertFalse(self::validate(self::SNAPSHOT, self::decoded($snapshot))->isValid());
    }

    #[Test]
    public function every_seeded_store_fits_the_contract(): void
    {
        foreach (['arara', 'bemtevi', 'sabia'] as $store) {
            $snapshot = ProductSnapshot::of(Products::book());
            $snapshot['store'] = $store;

            self::assertMatchesContract(self::SNAPSHOT, self::decoded($snapshot));
        }
    }

    #[Test]
    public function the_contract_keeps_drafts_out(): void
    {
        $draft = ProductSnapshot::of(Products::book(ProductStatus::Draft));

        self::assertFalse(self::validate(self::SNAPSHOT, self::decoded($draft))->isValid());
    }

    #[Test]
    public function the_contract_requires_the_version(): void
    {
        $snapshot = ProductSnapshot::of(Products::book());
        unset($snapshot['version']);

        self::assertFalse(self::validate(self::SNAPSHOT, self::decoded($snapshot))->isValid());
    }

    private static function assertMatchesContract(string $schema, mixed $data): void
    {
        $result = self::validate($schema, $data);
        $error = $result->error();

        self::assertTrue($result->isValid(), $error === null ? '' : (string) json_encode((new ErrorFormatter())->format($error)));
    }

    private static function validate(string $schema, mixed $data): ValidationResult
    {
        $validator = new Validator();
        $validator->resolver()?->registerFile('urn:tucano:' . $schema, self::CONTRACTS . $schema);

        return $validator->validate($data, 'urn:tucano:' . $schema);
    }

    /**
     * Opis reads JSON objects as stdClass, the way json_decode returns them.
     *
     * @param array<string, mixed> $data
     */
    private static function decoded(array $data): mixed
    {
        return json_decode((string) json_encode($data), flags: JSON_THROW_ON_ERROR);
    }
}
