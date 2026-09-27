<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity\Snowflake;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;
use Tucano\SharedKernel\Identity\CrockfordBase32;

/**
 * Twitter's Snowflake layout in 64 bits:
 *
 *   0 | 41 bits: ms since EPOCH | 5 bits: datacenter | 5 bits: worker | 12 bits: sequence
 *
 * The id sorts by creation time and explains itself: timestamp, node and
 * sequence can all be read back from it.
 */
final readonly class Snowflake implements Stringable, JsonSerializable
{
    /** 2026-01-01T00:00:00Z */
    public const int EPOCH_MILLIS = 1_767_225_600_000;

    public const int MAX_SEQUENCE = 4095;

    private const int MAX_ELAPSED_MILLIS = (1 << 41) - 1;
    private const int TIMESTAMP_SHIFT = 22;
    private const int DATACENTER_SHIFT = 17;
    private const int WORKER_SHIFT = 12;

    private function __construct(private int $value) {}

    public static function compose(int $unixMillis, NodeId $node, int $sequence): self
    {
        $elapsed = $unixMillis - self::EPOCH_MILLIS;
        if ($elapsed < 0 || $elapsed > self::MAX_ELAPSED_MILLIS) {
            throw new InvalidArgumentException(sprintf('Timestamp %d is outside the Snowflake range.', $unixMillis));
        }
        if ($sequence < 0 || $sequence > self::MAX_SEQUENCE) {
            throw new InvalidArgumentException(sprintf('Sequence %d is outside 0..%d.', $sequence, self::MAX_SEQUENCE));
        }

        return new self(
            ($elapsed << self::TIMESTAMP_SHIFT)
            | ($node->datacenter << self::DATACENTER_SHIFT)
            | ($node->worker << self::WORKER_SHIFT)
            | $sequence,
        );
    }

    public static function fromInt(int $value): self
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf('%d is not a valid Snowflake.', $value));
        }

        return new self($value);
    }

    public static function fromString(string $decimal): self
    {
        $value = filter_var($decimal, FILTER_VALIDATE_INT);
        if (!is_int($value)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid Snowflake.', $decimal));
        }

        return self::fromInt($value);
    }

    public static function fromBase32(string $encoded): self
    {
        return self::fromInt(CrockfordBase32::decode($encoded));
    }

    public function createdAt(): DateTimeImmutable
    {
        $millis = ($this->value >> self::TIMESTAMP_SHIFT) + self::EPOCH_MILLIS;
        $createdAt = DateTimeImmutable::createFromFormat('U.v', sprintf('%d.%03d', intdiv($millis, 1000), $millis % 1000));
        if ($createdAt === false) {
            throw new InvalidArgumentException(sprintf('Cannot read the timestamp of %d.', $this->value));
        }

        return $createdAt->setTimezone(new DateTimeZone('UTC'));
    }

    public function node(): NodeId
    {
        return new NodeId(
            ($this->value >> self::DATACENTER_SHIFT) & NodeId::MAX,
            ($this->value >> self::WORKER_SHIFT) & NodeId::MAX,
        );
    }

    public function sequence(): int
    {
        return $this->value & self::MAX_SEQUENCE;
    }

    public function equals(self $other): bool
    {
        return $other->value === $this->value;
    }

    public function toInt(): int
    {
        return $this->value;
    }

    public function toBase32(): string
    {
        return CrockfordBase32::encode($this->value);
    }

    public function toString(): string
    {
        return (string) $this->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    /**
     * Serialized as a string on purpose: JavaScript numbers lose precision above
     * 2^53, which is exactly why Twitter's API added the id_str field.
     */
    public function jsonSerialize(): string
    {
        return $this->toString();
    }
}
