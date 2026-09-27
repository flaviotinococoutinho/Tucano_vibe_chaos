<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Domain;

/**
 * What kind of problem a domain error is, in business terms. Adapters decide
 * what that means for their protocol (HTTP status, retry or DLQ for messages).
 */
enum ErrorCategory: string
{
    case NotFound = 'not_found';
    case Conflict = 'conflict';
    case InvalidInput = 'invalid_input';
    case Forbidden = 'forbidden';
    case Unavailable = 'unavailable';
}
