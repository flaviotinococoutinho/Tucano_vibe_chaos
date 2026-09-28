<?php

declare(strict_types=1);

namespace Commerce\Shared\Adapter\Driving\Http;

use Illuminate\Http\Request;

/**
 * The preferences of a request (RFC 7240, the Prefer header): hints about how the client
 * would like the server to behave, which the server may follow or ignore. Names are case
 * insensitive, parameters after ";" are left out, and when a preference comes twice only
 * the first counts, as the RFC asks.
 */
final readonly class Preferences
{
    /** @param array<string, string> $values */
    private function __construct(private array $values) {}

    public static function of(Request $request): self
    {
        $values = [];
        foreach ($request->headers->all('prefer') as $header) {
            foreach (explode(',', (string) $header) as $preference) {
                $pair = trim(explode(';', $preference, 2)[0]);
                if ($pair === '') {
                    continue;
                }
                [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $values[strtolower(trim($name))] ??= trim(trim($value), '"');
            }
        }

        return new self($values);
    }

    /** The value of a preference, or null when the request does not state it. */
    public function valueOf(string $name): ?string
    {
        $value = $this->values[strtolower($name)] ?? null;

        return $value === '' ? null : $value;
    }
}
