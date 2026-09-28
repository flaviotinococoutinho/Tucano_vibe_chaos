import { DomainError } from '../platform/domain-error.ts';

/** TX plus 13 Crockford Base32 symbols (CHAR(15)), as Logistics publishes it. */
export const TRACKING_CODE = /^TX[0-9A-HJKMNP-TV-Z]{13}$/;

/**
 * What the browser lets through before the BFF reads the code: any case, spaces around.
 * The BFF does the rest, so the pattern helps without refusing a code people can read.
 */
export const TYPED_TRACKING_CODE = '^\\s*[Tt][Xx][0-9A-Za-z]{13}\\s*$';

/**
 * The code as people type it, made canonical: no spaces around, uppercase, and the
 * letters Crockford Base32 reads as digits (O as 0, I and L as 1), so a code copied
 * from a label with the wrong letter still finds its parcel.
 */
export function canonicalCode(typed: string): string {
  const upper = typed.trim().toUpperCase();
  if (!upper.startsWith('TX')) {
    return upper;
  }
  return `TX${upper.slice(2).replaceAll('O', '0').replaceAll('I', '1').replaceAll('L', '1')}`;
}

export class InvalidTrackingCode extends DomainError {
  readonly category = 'invalid_input';

  constructor() {
    const message = 'O código tem TX e mais 13 letras e números, como TX02PX83Y5M5G00.';
    super(message, { fieldErrors: { code: [message] } });
  }
}

export class TrackingNotFound extends DomainError {
  readonly category = 'not_found';

  constructor(code: string) {
    super(
      `Ainda não há notícias da entrega ${code}. Se o pedido acabou de ser pago, elas chegam em alguns segundos.`,
    );
  }
}
