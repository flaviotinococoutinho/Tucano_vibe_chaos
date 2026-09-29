import { createHmac, timingSafeEqual } from 'node:crypto';
import { MAX_NAME_LENGTH, MAX_PROFILES, type Profile, type Session } from './session.ts';

/** RFC 6265 asks browsers for at least 4096 bytes per cookie; a longer value is not ours. */
const MAX_SEALED_LENGTH = 4096;

const BASE64URL = /^[A-Za-z0-9_-]+$/;
const UUID_V7 = /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;
const CONTROL_CHARACTER = /\p{Cc}/u;

/** Why a sealed session did not open: the log says it, never the value itself. */
export type SealProblem = 'oversized' | 'malformed' | 'signature' | 'content';

export type Unsealed = { readonly session: Session } | { readonly problem: SealProblem };

/**
 * The session as the cookie carries it: the JSON in base64url, a dot, and its HMAC-SHA256
 * in base64url. Anybody can read it; only who holds the secret can write one that opens.
 */
export function seal(session: Session, secret: string): string {
  const json = JSON.stringify({
    active: session.active,
    profiles: session.profiles.map(({ id, name }) => ({ id, name })),
  });
  const payload = Buffer.from(json, 'utf8').toString('base64url');

  return `${payload}.${signatureOf(payload, secret)}`;
}

/**
 * Opens a sealed session. The signature is checked first, in constant time, so nothing of
 * a forged value is ever parsed; then the content is checked like any input, because a
 * valid signature over a shape this code no longer reads is still not a session.
 */
export function unseal(value: string, secret: string): Unsealed {
  if (value.length > MAX_SEALED_LENGTH) {
    return { problem: 'oversized' };
  }
  const [payload = '', signature = '', ...rest] = value.split('.');
  if (rest.length > 0 || !BASE64URL.test(payload) || !BASE64URL.test(signature)) {
    return { problem: 'malformed' };
  }
  if (!sameText(signature, signatureOf(payload, secret))) {
    return { problem: 'signature' };
  }
  let content: unknown;
  try {
    content = JSON.parse(Buffer.from(payload, 'base64url').toString('utf8'));
  } catch {
    return { problem: 'content' };
  }
  const session = sessionFrom(content);

  return session === null ? { problem: 'content' } : { session };
}

function signatureOf(payload: string, secret: string): string {
  return createHmac('sha256', secret).update(payload).digest('base64url');
}

/** timingSafeEqual needs equal lengths, and the length of a signature is no secret. */
function sameText(given: string, expected: string): boolean {
  const givenBytes = Buffer.from(given);
  const expectedBytes = Buffer.from(expected);
  return givenBytes.length === expectedBytes.length && timingSafeEqual(givenBytes, expectedBytes);
}

function sessionFrom(content: unknown): Session | null {
  if (!isRecord(content) || typeof content.active !== 'string') {
    return null;
  }
  const { active, profiles } = content;
  if (!Array.isArray(profiles) || profiles.length === 0 || profiles.length > MAX_PROFILES) {
    return null;
  }
  const valid = profiles.map(profileFrom).filter((profile) => profile !== null);
  const ids = new Set(valid.map(({ id }) => id));
  if (valid.length !== profiles.length || ids.size !== valid.length || !ids.has(active)) {
    return null;
  }

  return { active, profiles: valid };
}

function profileFrom(content: unknown): Profile | null {
  if (!isRecord(content) || typeof content.id !== 'string' || !UUID_V7.test(content.id)) {
    return null;
  }
  const { id, name } = content;
  if (name === null) {
    return { id, name: null };
  }
  const tidy =
    typeof name === 'string' &&
    name === name.trim() &&
    name.length >= 1 &&
    name.length <= MAX_NAME_LENGTH &&
    !CONTROL_CHARACTER.test(name);

  return tidy ? { id, name } : null;
}

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}
