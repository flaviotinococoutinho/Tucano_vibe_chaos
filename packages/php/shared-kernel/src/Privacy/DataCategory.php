<?php

declare(strict_types=1);

namespace Tucano\SharedKernel\Privacy;

/**
 * What kind of sensitive data a value is. Each kind knows how it shows when it leaks
 * by accident, and which rule asks for the care: the LGPD for personal data, the PCI
 * DSS for what charges a card.
 */
enum DataCategory: string
{
    case PersonName = 'person_name';
    case Email = 'email';
    /** A CPF, an RG or any document a person shows at the door. */
    case Document = 'document';
    /** Not card data (the PSP keeps the number), but it charges the card: same care. */
    case CardToken = 'card_token';

    /**
     * Enough for a person reading a log to tell two values apart, too little to use them.
     * The masks have a fixed width, so they do not tell the length of what they hide.
     */
    public function mask(string $value): string
    {
        return match ($this) {
            self::PersonName => implode(' ', array_map(
                static fn(string $word): string => mb_substr($word, 0, 1) . '***',
                preg_split('/\s+/u', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'],
            )),
            self::Email => str_contains($value, '@')
                ? mb_substr($value, 0, 1) . '***@' . substr($value, (int) strrpos($value, '@') + 1)
                : '***',
            self::Document => '***' . mb_substr($value, -2),
            self::CardToken => (str_contains($value, '_') ? strstr($value, '_', true) . '_' : '') . '***',
        };
    }

    public function regime(): string
    {
        return match ($this) {
            self::PersonName, self::Email, self::Document => 'LGPD',
            self::CardToken => 'PCI DSS',
        };
    }
}
