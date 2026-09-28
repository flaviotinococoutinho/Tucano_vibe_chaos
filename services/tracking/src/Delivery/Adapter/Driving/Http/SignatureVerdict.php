<?php

declare(strict_types=1);

namespace Tracking\Delivery\Adapter\Driving\Http;

enum SignatureVerdict: string
{
    case Valid = 'valid';
    case Malformed = 'malformed';
    case Stale = 'stale';
    case Mismatch = 'mismatch';
}
