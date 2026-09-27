<?php

declare(strict_types=1);

namespace Tracking\Platform\Http;

use RuntimeException;

/** Input an endpoint refuses, with what is wrong about each field. */
final class ValidationFailed extends RuntimeException
{
    /** @param array<string, list<string>> $errors messages by field */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The given data was invalid.');
    }
}
