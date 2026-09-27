<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use Ramsey\Uuid\Uuid;

/**
 * The id Kong puts on every request (uuid#counter), or a new UUIDv7 when there
 * is none. A value that is not a short printable token is replaced as well, so
 * a client cannot push junk into every log line of the request.
 */
final readonly class CorrelationId
{
    public const string HEADER = 'X-Correlation-Id';

    private function __construct(public string $value) {}

    public static function fromHeader(?string $header): self
    {
        if ($header !== null && preg_match('/^[\x21-\x7E]{1,128}$/', $header) === 1) {
            return new self($header);
        }

        return new self(Uuid::uuid7()->toString());
    }
}
