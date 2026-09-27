<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driving\Http;

use Commerce\Payments\Application\OutcomeKind;
use Commerce\Payments\Application\ProviderOutcome;
use Commerce\Payments\Domain\PaymentId;
use InvalidArgumentException;

/**
 * The anticorruption layer on the way in: PayFake's events (charge.succeeded,
 * reference, failureCode) become Payments' outcomes. Event types Payments does
 * not know are left alone, a tolerant reader.
 */
final class PayFakeEvents
{
    private function __construct() {}

    /**
     * @param array<mixed> $event
     *
     * @throws InvalidArgumentException when a known event is missing what it must carry
     */
    public static function outcomeOf(array $event): ?ProviderOutcome
    {
        $kind = match ($event['type'] ?? null) {
            'charge.succeeded' => OutcomeKind::Captured,
            'charge.failed' => OutcomeKind::Failed,
            'refund.succeeded' => OutcomeKind::Refunded,
            default => null,
        };
        if ($kind === null) {
            return null;
        }
        $data = $event['data'] ?? null;
        if (!is_array($data)) {
            throw new InvalidArgumentException('The event has no data.');
        }
        $failureCode = $data['failureCode'] ?? null;

        return new ProviderOutcome(
            self::text($event, 'id'),
            PaymentId::fromString(self::text($data, 'reference')),
            self::text($data, 'chargeId'),
            $kind,
            is_string($failureCode) ? $failureCode : null,
        );
    }

    /** @param array<mixed> $fields */
    private static function text(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : throw new InvalidArgumentException(sprintf('The event has no %s.', $name));
    }
}
