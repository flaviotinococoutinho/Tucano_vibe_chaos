import { createHash } from 'node:crypto';
import type { FastifyRequest } from 'fastify';
import type { Clock } from './clock.ts';
import { ExpiringMap, type Retention } from './expiring-map.ts';
import { DomainError } from './platform/domain-error.ts';

const HEADER = 'idempotency-key';
const MAX_KEY_LENGTH = 255;

type Saved<T> = { readonly fingerprint: string; readonly response: T };

export type Remembered<T> = { readonly response: T; readonly replayed: boolean };

/**
 * What each Idempotency-Key answered. Only successes are kept: after an error, the client
 * can retry with the same key and the request runs again. Shared by every simulator that
 * takes a POST with an Idempotency-Key (PayFake's charges and refunds, CarrierFake's pickups).
 */
export class IdempotencyKeys<T> {
  private readonly saved: ExpiringMap<Saved<T>>;

  constructor(clock: Clock, retention: Retention) {
    this.saved = new ExpiringMap(clock, retention);
  }

  /**
   * Replays what `key` answered before, or runs `create` and keeps its answer. `create`
   * is synchronous, so two requests racing with the same key cannot both run it.
   */
  remember(key: string, request: unknown, create: () => T): Remembered<T> {
    const fingerprint = fingerprintOf(request);
    const saved = this.saved.get(key);
    if (saved === undefined) {
      const response = create();
      this.saved.set(key, { fingerprint, response });
      return { response, replayed: false };
    }
    if (saved.fingerprint !== fingerprint) {
      throw new IdempotencyKeyReused(key);
    }

    return { response: saved.response, replayed: true };
  }
}

/** The key a creating request must carry. */
export function idempotencyKeyOf(request: FastifyRequest): string {
  const key = request.headers[HEADER];
  if (typeof key !== 'string' || key.trim() === '') {
    throw new InvalidIdempotencyKey('The Idempotency-Key header is required.');
  }
  if (key.length > MAX_KEY_LENGTH) {
    throw new InvalidIdempotencyKey(
      `The Idempotency-Key header must have at most ${MAX_KEY_LENGTH} characters.`,
    );
  }

  return key;
}

/** A protocol error, not a domain one: it carries its HTTP status, like Fastify's own errors. */
export class InvalidIdempotencyKey extends Error {
  readonly statusCode = 400;

  constructor(message: string) {
    super(message);
    this.name = 'InvalidIdempotencyKey';
  }
}

export class IdempotencyKeyReused extends DomainError {
  readonly category = 'invalid_input';

  constructor(key: string) {
    super(`The Idempotency-Key "${key}" was already used with a different request.`);
  }
}

/** SHA-256 of the request as JSON with sorted keys, so the order of the fields does not count. */
function fingerprintOf(request: unknown): string {
  return createHash('sha256').update(JSON.stringify(request, sortKeys)).digest('hex');
}

function sortKeys(_key: string, value: unknown): unknown {
  if (value === null || typeof value !== 'object' || Array.isArray(value)) {
    return value;
  }

  return Object.fromEntries(Object.entries(value).sort(([a], [b]) => (a < b ? -1 : 1)));
}
