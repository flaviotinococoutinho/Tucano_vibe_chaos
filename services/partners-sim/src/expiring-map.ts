import type { Clock } from './clock.ts';

export type Retention = {
  /** How long an entry lives after it was first stored. */
  readonly ttlMs: number;
  /** Past this size, the oldest entry goes first. */
  readonly maxEntries: number;
};

type Entry<V> = { readonly value: V; readonly expiresAt: number };

/**
 * A Map that forgets each entry some time after it was first stored, and the oldest ones
 * early when it grows past its limit. The simulator keeps its state in memory, and this is
 * what keeps a long experiment inside the container's memory limit.
 */
export class ExpiringMap<V> {
  private readonly entries = new Map<string, Entry<V>>();
  private readonly clock: Clock;
  private readonly retention: Retention;

  constructor(clock: Clock, retention: Retention) {
    this.clock = clock;
    this.retention = retention;
  }

  get(key: string): V | undefined {
    const entry = this.entries.get(key);

    return entry !== undefined && entry.expiresAt > this.clock.now() ? entry.value : undefined;
  }

  /** Replacing a live entry keeps its expiry: a change does not extend its life. */
  set(key: string, value: V): void {
    const now = this.clock.now();
    const current = this.entries.get(key);
    if (current !== undefined && current.expiresAt > now) {
      this.entries.set(key, { value, expiresAt: current.expiresAt });
      return;
    }

    this.forgetExpired(now);
    this.entries.set(key, { value, expiresAt: now + this.retention.ttlMs });
    if (this.entries.size > this.retention.maxEntries) {
      this.forgetOldest();
    }
  }

  // A Map iterates in insertion order and every entry lives just as long,
  // so the expired entries are always the first ones.
  private forgetExpired(now: number): void {
    for (const [key, entry] of this.entries) {
      if (entry.expiresAt > now) {
        return;
      }
      this.entries.delete(key);
    }
  }

  private forgetOldest(): void {
    const oldest = this.entries.keys().next();
    if (oldest.done !== true) {
      this.entries.delete(oldest.value);
    }
  }
}
