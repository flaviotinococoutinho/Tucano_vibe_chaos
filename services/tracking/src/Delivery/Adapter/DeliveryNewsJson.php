<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;
use Tracking\Delivery\Domain\CourierPosition;
use Tracking\Delivery\Domain\DeliveryEnded;
use Tracking\Delivery\Domain\DeliveryNews;
use Tracking\Delivery\Domain\TrackingCode;
use Tracking\Delivery\Domain\VisitOutcome;
use Tracking\Platform\Http\ValidationFailed;

/**
 * The delivery news as contracts/tracking writes it, one JSON object, the same shape in the
 * report of the device, in Redis and in the frame a follower receives. Decoding says what is
 * wrong with each field, so a device gets every mistake at once.
 */
final class DeliveryNewsJson
{
    private const string RFC_3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    private function __construct() {}

    public static function encode(DeliveryNews $news): string
    {
        return json_encode($news->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(string $json): DeliveryNews
    {
        try {
            $fields = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationFailed(['body' => ['The body is not JSON.']]);
        }
        if (!is_array($fields) || array_is_list($fields)) {
            throw new ValidationFailed(['body' => ['The body is not a delivery news object.']]);
        }

        return match ($fields['type'] ?? null) {
            'position' => self::position($fields),
            'ended' => self::ended($fields),
            default => throw new ValidationFailed(['type' => ['The type is position or ended.']]),
        };
    }

    /** @param array<mixed> $fields */
    private static function position(array $fields): CourierPosition
    {
        $errors = [];
        $code = self::trackingCode($fields, $errors);
        $latitude = self::degrees($fields, 'latitude', 90, $errors);
        $longitude = self::degrees($fields, 'longitude', 180, $errors);
        $at = self::at($fields, $errors);
        $remaining = $fields['remainingMeters'] ?? null;
        if (!is_int($remaining) || $remaining < 0) {
            $errors['remainingMeters'][] = 'The remaining meters are a whole number, never negative.';
        }
        if ($code === null || $latitude === null || $longitude === null || $at === null || !is_int($remaining) || $remaining < 0) {
            throw new ValidationFailed($errors);
        }

        return CourierPosition::of($code, $latitude, $longitude, $at, $remaining);
    }

    /** @param array<mixed> $fields */
    private static function ended(array $fields): DeliveryEnded
    {
        $errors = [];
        $code = self::trackingCode($fields, $errors);
        $at = self::at($fields, $errors);
        $outcome = is_string($fields['outcome'] ?? null) ? VisitOutcome::tryFrom($fields['outcome']) : null;
        if ($outcome === null) {
            $errors['outcome'][] = 'The outcome is delivered or delivery_failed.';
        }
        if ($code === null || $at === null || $outcome === null) {
            throw new ValidationFailed($errors);
        }

        return DeliveryEnded::of($code, $outcome, $at);
    }

    /**
     * @param array<mixed> $fields
     * @param array<string, list<string>> $errors
     */
    private static function trackingCode(array $fields, array &$errors): ?TrackingCode
    {
        try {
            return TrackingCode::of(is_string($fields['trackingCode'] ?? null) ? $fields['trackingCode'] : '');
        } catch (InvalidArgumentException $invalid) {
            $errors['trackingCode'][] = $invalid->getMessage();

            return null;
        }
    }

    /**
     * @param array<mixed> $fields
     * @param array<string, list<string>> $errors
     */
    private static function degrees(array $fields, string $name, int $limit, array &$errors): ?float
    {
        $value = $fields[$name] ?? null;
        if ((!is_int($value) && !is_float($value)) || $value < -$limit || $value > $limit) {
            $errors[$name][] = sprintf('The %s is a number of degrees between -%d and %d.', $name, $limit, $limit);

            return null;
        }

        return (float) $value;
    }

    /**
     * @param array<mixed> $fields
     * @param array<string, list<string>> $errors
     */
    private static function at(array $fields, array &$errors): ?DateTimeImmutable
    {
        $value = $fields['at'] ?? null;
        if (!is_string($value) || preg_match(self::RFC_3339, $value) !== 1) {
            $errors['at'][] = 'The time is RFC 3339, such as 2026-09-28T21:56:13.634Z.';

            return null;
        }

        return new DateTimeImmutable($value);
    }
}
