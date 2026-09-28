<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Identity;

/**
 * The id of a domain event: a UUIDv7, so events sort by the moment they
 * happened. Aggregates ask for one here and never meet the library that makes
 * it, which can change without touching a single one of them.
 */
final readonly class EventId extends UuidIdentifier {}
