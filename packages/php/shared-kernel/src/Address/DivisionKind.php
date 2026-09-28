<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Address;

/**
 * A kind of territorial division, as IBGE divides Brazil: state (UF),
 * municipality, district and subdistrict, and below them the neighborhood
 * each municipality draws. Another kind of division (the administrative
 * regions of the Federal District, say) is one more case with its rank.
 */
enum DivisionKind: string
{
    case State = 'state';
    case Municipality = 'municipality';
    case District = 'district';
    case Subdistrict = 'subdistrict';
    case Neighborhood = 'neighborhood';

    /** From the broadest to the narrowest: a division only holds divisions of a greater rank. */
    public function rank(): int
    {
        return match ($this) {
            self::State => 1,
            self::Municipality => 2,
            self::District => 3,
            self::Subdistrict => 4,
            self::Neighborhood => 5,
        };
    }

    /**
     * Digits of the IBGE geocode of the kind, which extends the geocode of the
     * division above it; null for the state, known by its UF, and for the
     * neighborhood, which has no national code.
     */
    public function geocodeLength(): ?int
    {
        return match ($this) {
            self::Municipality => 7,
            self::District => 9,
            self::Subdistrict => 11,
            self::State, self::Neighborhood => null,
        };
    }
}
