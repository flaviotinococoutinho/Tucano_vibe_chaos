import { createHmac, timingSafeEqual } from 'node:crypto';

/** How far the signed time may be from the receiver's clock: five minutes either way. */
export const TOLERANCE_SECONDS = 300;

/** What a receiver makes of a signature. Only `valid` means the webhook can be trusted. */
export type Verdict = 'valid' | 'malformed' | 'stale' | 'mismatch';

export type SignedWebhook = {
  readonly secret: string;
  /** The raw body, byte for byte as it arrived: parsing and serializing again breaks the HMAC. */
  readonly payload: string;
  readonly header: string;
  /** The receiver's clock, in Unix seconds. */
  readonly now: number;
  readonly toleranceSeconds?: number;
};

/**
 * `t=<unix seconds>,v1=<hex HMAC-SHA256 of "<t>.<raw body>">`. The time is inside the HMAC,
 * so a captured webhook cannot be sent again later with a fresh timestamp. Every simulator
 * signs this way; only the header it travels under changes (`PayFake-Signature`,
 * `Carrier-Signature`...).
 */
export function sign(secret: string, payload: string, timestamp: number): string {
  return `t=${timestamp},v1=${hmac(secret, timestamp, payload)}`;
}

/**
 * What a receiver does with the header, and what commerce and logistics mirror in PHP: parse
 * it, refuse a time outside the tolerance, then compare the HMAC in constant time. The header
 * may carry several `v1` values, and one match is enough, which lets a secret rotate without
 * downtime.
 */
export function verify({
  secret,
  payload,
  header,
  now,
  toleranceSeconds = TOLERANCE_SECONDS,
}: SignedWebhook): Verdict {
  const signature = parse(header);
  if (signature === undefined) {
    return 'malformed';
  }
  if (Math.abs(now - signature.timestamp) > toleranceSeconds) {
    return 'stale';
  }
  const expected = Buffer.from(hmac(secret, signature.timestamp, payload), 'hex');
  const matches = signature.candidates.some((candidate) =>
    timingSafeEqual(Buffer.from(candidate, 'hex'), expected),
  );

  return matches ? 'valid' : 'mismatch';
}

function hmac(secret: string, timestamp: number, payload: string): string {
  return createHmac('sha256', secret).update(`${timestamp}.${payload}`).digest('hex');
}

type ParsedSignature = { readonly timestamp: number; readonly candidates: readonly string[] };

function parse(header: string): ParsedSignature | undefined {
  let timestamp: number | undefined;
  const candidates: string[] = [];
  for (const part of header.split(',')) {
    const [name, value = ''] = part.trim().split('=', 2);
    if (name === 't' && /^\d{1,12}$/.test(value)) {
      timestamp = Number(value);
    }
    if (name === 'v1' && /^[0-9a-f]{64}$/i.test(value)) {
      candidates.push(value);
    }
  }

  return timestamp === undefined || candidates.length === 0 ? undefined : { timestamp, candidates };
}
