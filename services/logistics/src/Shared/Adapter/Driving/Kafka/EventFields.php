<?php

declare(strict_types=1);

namespace Logistics\Shared\Adapter\Driving\Kafka;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * Typed reads over the data of a CloudEvent. A field that is missing or has
 * another type makes the event unreadable; fields nobody asks for are ignored,
 * so the producer can add new ones (tolerant reader).
 */
final readonly class EventFields
{
    /** @param array<mixed> $fields */
    public function __construct(private array $fields, private string $path = '') {}

    public function text(string $name): string
    {
        $value = $this->fields[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : throw $this->missing('text', $name);
    }

    /** A UUID in text, checked here so a bad one fails as unreadable instead of being retried against a uuid column. */
    public function uuid(string $name): string
    {
        $value = $this->text($name);

        return Uuid::isValid($value) ? $value : throw $this->missing('UUID', $name);
    }

    public function optionalText(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;

        return $value === null || is_string($value) ? $value : throw $this->missing('text or null', $name);
    }

    public function integer(string $name): int
    {
        $value = $this->fields[$name] ?? null;

        return is_int($value) ? $value : throw $this->missing('integer', $name);
    }

    public function optionalNumber(string $name): ?float
    {
        $value = $this->fields[$name] ?? null;

        return $value === null || is_int($value) || is_float($value) ? $value : throw $this->missing('number or null', $name);
    }

    public function object(string $name): self
    {
        $value = $this->fields[$name] ?? null;

        return is_array($value) && !array_is_list($value) ? new self($value, $this->pathTo($name)) : throw $this->missing('object', $name);
    }

    /** @return list<self> */
    public function objects(string $name): array
    {
        $values = $this->fields[$name] ?? null;
        if (!is_array($values) || !array_is_list($values)) {
            throw $this->missing('list', $name);
        }

        $objects = [];
        foreach ($values as $index => $value) {
            $element = sprintf('%s[%d]', $name, $index);
            $objects[] = is_array($value) ? new self($value, $this->pathTo($element)) : throw $this->missing('object', $element);
        }

        return $objects;
    }

    private function missing(string $type, string $name): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf('The event has no %s %s.', $type, $this->pathTo($name)));
    }

    private function pathTo(string $name): string
    {
        return $this->path === '' ? $name : $this->path . '.' . $name;
    }
}
