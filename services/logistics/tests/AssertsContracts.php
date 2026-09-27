<?php

declare(strict_types=1);

namespace Tests;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\ValidationResult;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;

/** Validates JSON against the schemas in contracts/events, the published language between the services. */
trait AssertsContracts
{
    /** @param mixed $json decoded with objects (json_decode without the associative flag), as JSON Schema expects */
    protected static function assertMatchesContract(string $schema, mixed $json): void
    {
        $result = self::validation($schema, $json);

        Assert::assertTrue($result->isValid(), sprintf('%s: %s', $schema, json_encode(
            $result->error() === null ? [] : (new ErrorFormatter())->format($result->error()),
            JSON_THROW_ON_ERROR,
        )));
    }

    protected static function assertBreaksContract(string $schema, mixed $json): void
    {
        Assert::assertFalse(self::validation($schema, $json)->isValid(), sprintf('%s accepted data it should refuse.', $schema));
    }

    /** JSON Schema tells objects from arrays, so PHP arrays go through JSON first. */
    protected static function asJson(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), flags: JSON_THROW_ON_ERROR);
    }

    private static function validation(string $schema, mixed $json): ValidationResult
    {
        $validator = new Validator();
        $validator->resolver()?->registerFile('urn:tucano:' . $schema, dirname(__DIR__, 3) . '/contracts/events/' . $schema);

        return $validator->validate($json, 'urn:tucano:' . $schema);
    }
}
