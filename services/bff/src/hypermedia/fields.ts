import type { Field } from './siren.ts';

/** Makes the key of one render of a form; the app uses UUIDv7, the tests a fixed one. */
export type KeyMaker = () => string;

/**
 * The hidden key every action that changes something carries, fresh on each render. A
 * double click or a retry sends the same key, and the service answers the same thing
 * instead of doing it twice (draft-ietf-httpapi-idempotency-key-header).
 */
export function idempotencyKeyField(key: string): Field {
  return { name: 'idempotencyKey', type: 'hidden', value: key };
}

export function hidden(name: string, value: string | number): Field {
  return { name, type: 'hidden', value };
}
