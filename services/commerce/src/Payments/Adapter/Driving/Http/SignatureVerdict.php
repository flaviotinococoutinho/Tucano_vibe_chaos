<?php

declare(strict_types=1);

namespace Commerce\Payments\Adapter\Driving\Http;

enum SignatureVerdict: string
{
    case Valid = 'valid';
    case Malformed = 'malformed';
    case Stale = 'stale';
    case Mismatch = 'mismatch';
}
