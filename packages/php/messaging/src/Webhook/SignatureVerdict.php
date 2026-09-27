<?php

declare(strict_types=1);

namespace Tucano\Messaging\Webhook;

enum SignatureVerdict: string
{
    case Valid = 'valid';
    case Malformed = 'malformed';
    case Stale = 'stale';
    case Mismatch = 'mismatch';
}
