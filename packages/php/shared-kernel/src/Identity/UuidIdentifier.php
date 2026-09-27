<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Ramsey\Uuid\Rfc4122\FieldsInterface;
use Ramsey\Uuid\Uuid;
use Stringable;

/**
 * Identity of an aggregate: a UUIDv7 created by the domain before anything is
 * persisted. Version 7 keeps inserts ordered in time, which B-tree indexes like.
 */
abstract readonly class UuidIdentifier implements Stringable
{
    final protected function __construct(private string $value) {}

    public static function generate(): static
    {
        return new static(Uuid::uuid7()->toString());
    }

    public static function fromString(string $value): static
    {
        if (!Uuid::isValid($value) || !self::isVersion7($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a UUIDv7.', $value));
        }

        return new static(strtolower($value));
    }

    private static function isVersion7(string $value): bool
    {
        // fromString() returns a lazy UUID, so check the version field, not the class.
        $fields = Uuid::fromString($value)->getFields();

        return $fields instanceof FieldsInterface && $fields->getVersion() === 7;
    }

    public static function fromBytes(string $bytes): static
    {
        return static::fromString(Uuid::fromBytes($bytes)->toString());
    }

    public function equals(self $other): bool
    {
        return $other instanceof static && $other->value === $this->value;
    }

    public function createdAt(): DateTimeImmutable
    {
        $createdAt = Uuid::fromString($this->value)->getDateTime();

        return DateTimeImmutable::createFromInterface($createdAt)->setTimezone(new DateTimeZone('UTC'));
    }

    public function toBytes(): string
    {
        return Uuid::fromString($this->value)->getBytes();
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
