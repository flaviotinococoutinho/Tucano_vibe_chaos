<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Messaging;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use Tucano\SharedKernel\Domain\DomainEvent;

/**
 * CloudEvents 1.0 envelope in structured mode: the whole JSON goes in the
 * Kafka message value, and the subject doubles as the message key.
 */
final readonly class CloudEvent
{
    public const string SPEC_VERSION = '1.0';
    public const string CONTENT_TYPE = 'application/cloudevents+json';

    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.v\Z';

    /** @param array<string, mixed> $data */
    public function __construct(
        public string $id,
        public string $source,
        public string $type,
        public string $subject,
        public DateTimeImmutable $time,
        public string $correlationId,
        public ?string $causationId,
        public array $data,
    ) {}

    public static function fromDomainEvent(
        DomainEvent $event,
        string $source,
        string $correlationId,
        ?string $causationId = null,
    ): self {
        return new self(
            $event->eventId(),
            $source,
            $event->eventType(),
            $event->aggregateId(),
            $event->occurredAt(),
            $correlationId,
            $causationId,
            $event->payload(),
        );
    }

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        $version = self::text($payload, 'specversion');
        if ($version !== self::SPEC_VERSION) {
            throw InvalidCloudEvent::unsupportedVersion($version);
        }
        $data = $payload['data'] ?? null;
        if (!is_array($data)) {
            throw InvalidCloudEvent::missing('data');
        }
        $causationId = $payload['causationid'] ?? null;

        /** @var array<string, mixed> $data */
        return new self(
            self::text($payload, 'id'),
            self::text($payload, 'source'),
            self::text($payload, 'type'),
            self::text($payload, 'subject'),
            self::instant(self::text($payload, 'time')),
            self::text($payload, 'correlationid'),
            is_string($causationId) ? $causationId : null,
            $data,
        );
    }

    /** A time that is not an instant makes the event invalid, like any other broken attribute. */
    private static function instant(string $time): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($time);
        } catch (DateMalformedStringException) {
            throw InvalidCloudEvent::malformed('time', $time);
        }
    }

    public static function fromJson(string $json): self
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw InvalidCloudEvent::missing('specversion');
        }

        /** @var array<string, mixed> $payload */
        return self::fromArray($payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $envelope = [
            'specversion' => self::SPEC_VERSION,
            'id' => $this->id,
            'source' => $this->source,
            'type' => $this->type,
            'subject' => $this->subject,
            'time' => $this->time->setTimezone(new DateTimeZone('UTC'))->format(self::TIME_FORMAT),
            'datacontenttype' => 'application/json',
            'correlationid' => $this->correlationId,
        ];
        if ($this->causationId !== null) {
            $envelope['causationid'] = $this->causationId;
        }
        $envelope['data'] = $this->data;

        return $envelope;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param array<string, mixed> $payload */
    private static function text(array $payload, string $attribute): string
    {
        $value = $payload[$attribute] ?? null;
        if (!is_string($value) || $value === '') {
            throw InvalidCloudEvent::missing($attribute);
        }

        return $value;
    }
}
