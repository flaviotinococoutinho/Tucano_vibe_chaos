<?php

declare(strict_types=1);

namespace App\Models;

use DateTimeImmutable;
use DateTimeZone;
use JsonSerializable;
use Tucano\SharedKernel\Money\Currency;
use Tucano\SharedKernel\Money\Money;

/**
 * A product as the catalog keeps it. The array form is what the API returns
 * and what the cache stores. A product is born in a store and never leaves it
 * (docs/adr/0031-a-store-is-a-tenant.md): no change or move takes it elsewhere.
 *
 * @phpstan-type ProductRecord array{
 *     id: string,
 *     sku: string,
 *     name: string,
 *     status: string,
 *     store: string,
 *     category: string,
 *     price: array{amount: int, currency: string},
 *     weightGrams: int,
 *     dimensions: array{lengthMm: int, widthMm: int, heightMm: int},
 *     version: int,
 *     updatedAt: string
 * }
 */
final readonly class Product implements JsonSerializable
{
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    public function __construct(
        public ProductId $id,
        public string $sku,
        public string $name,
        public ProductStatus $status,
        public string $store,
        public string $category,
        public Money $price,
        public int $weightGrams,
        public Dimensions $dimensions,
        public int $version,
        public DateTimeImmutable $updatedAt,
    ) {}

    public static function draft(NewProduct $input, DateTimeImmutable $now): self
    {
        return new self(
            ProductId::generate(),
            $input->sku,
            $input->name,
            ProductStatus::Draft,
            $input->store,
            $input->category,
            $input->price,
            $input->weightGrams,
            $input->dimensions,
            1,
            $now,
        );
    }

    public function revised(ProductChanges $changes, DateTimeImmutable $now): self
    {
        return new self(
            $this->id,
            $this->sku,
            $changes->name ?? $this->name,
            $this->status,
            $this->store,
            $changes->category ?? $this->category,
            $changes->price ?? $this->price,
            $changes->weightGrams ?? $this->weightGrams,
            $changes->dimensions ?? $this->dimensions,
            $this->version + 1,
            $now,
        );
    }

    /** Applies the move without judging it: ProductService checks the transition table first. */
    public function movedTo(ProductStatus $status, DateTimeImmutable $now): self
    {
        return new self(
            $this->id,
            $this->sku,
            $this->name,
            $status,
            $this->store,
            $this->category,
            $this->price,
            $this->weightGrams,
            $this->dimensions,
            $this->version + 1,
            $now,
        );
    }

    /** Same product data, whatever the version and the time of the last change. */
    public function sameStateAs(self $other): bool
    {
        return $other->id->equals($this->id)
            && $other->name === $this->name
            && $other->status === $this->status
            && $other->store === $this->store
            && $other->category === $this->category
            && $other->price->equals($this->price)
            && $other->weightGrams === $this->weightGrams
            && $other->dimensions->equals($this->dimensions);
    }

    /** @param ProductRecord $record */
    public static function fromArray(array $record): self
    {
        $dimensions = $record['dimensions'];

        return new self(
            ProductId::fromString($record['id']),
            $record['sku'],
            $record['name'],
            ProductStatus::from($record['status']),
            $record['store'],
            $record['category'],
            Money::of($record['price']['amount'], Currency::fromCode($record['price']['currency'])),
            $record['weightGrams'],
            Dimensions::fromArray($dimensions),
            $record['version'],
            new DateTimeImmutable($record['updatedAt']),
        );
    }

    /** @return ProductRecord */
    public function toArray(): array
    {
        return [
            'id' => $this->id->toString(),
            'sku' => $this->sku,
            'name' => $this->name,
            'status' => $this->status->value,
            'store' => $this->store,
            'category' => $this->category,
            'price' => $this->price->toArray(),
            'weightGrams' => $this->weightGrams,
            'dimensions' => $this->dimensions->toArray(),
            'version' => $this->version,
            'updatedAt' => $this->updatedAt->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT),
        ];
    }

    /** @return ProductRecord */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
