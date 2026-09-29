type Entry<V> = { readonly value: V; readonly at: number };

export type CacheOptions = {
  /** How long a value answers before the next read asks the service again. */
  readonly keepForMs: number;
  /** The most values kept at once: past it, the one kept longest ago leaves. */
  readonly maxEntries: number;
  /** The clock, in milliseconds. */
  readonly now: () => number;
};

/**
 * A few values read from a service, kept in memory for a while. Within its time a value
 * answers without asking anyone. Past it, the value still answers at once, while one call
 * behind it asks the service again (stale-while-revalidate, RFC 5861): a slow or absent
 * service never holds a screen that already knows the answer, and the value stays until the
 * service says something else. Reads of a key the service is being asked about wait for that
 * same call instead of asking again.
 */
export class Cache<V> {
  readonly #entries = new Map<string, Entry<V>>();
  readonly #asking = new Map<string, Promise<V | null>>();
  readonly #options: CacheOptions;

  constructor(options: CacheOptions) {
    this.#options = options;
  }

  /**
   * The value of the key, kept or asked for with `ask`, which answers null when the service
   * does not know the key: nothing is kept then, and a value kept before is forgotten.
   * `behind` hears how a call made behind a value that already answered failed.
   */
  async get(
    key: string,
    ask: () => Promise<V | null>,
    behind: (error: unknown) => void,
  ): Promise<V | null> {
    const kept = this.#entries.get(key);
    if (kept === undefined) {
      return this.#ask(key, ask);
    }
    if (this.#options.now() - kept.at >= this.#options.keepForMs) {
      this.#ask(key, ask).catch(behind);
    }

    return kept.value;
  }

  /** Keeps a value the service gave in another answer, like a store that came in the list. */
  put(key: string, value: V): void {
    // Deleted first, so the insertion order of the map is the order the values were kept in.
    this.#entries.delete(key);
    this.#entries.set(key, { value, at: this.#options.now() });
    if (this.#entries.size > this.#options.maxEntries) {
      const [oldest] = this.#entries.keys();
      if (oldest !== undefined) {
        this.#entries.delete(oldest);
      }
    }
  }

  #ask(key: string, ask: () => Promise<V | null>): Promise<V | null> {
    const asking = this.#asking.get(key);
    if (asking !== undefined) {
      return asking;
    }
    // The call starts on the next microtask, once it is registered, so a read that comes
    // while it is on its way always finds it.
    const call = Promise.resolve()
      .then(ask)
      .then((value) => {
        if (value === null) {
          this.#entries.delete(key);
        } else {
          this.put(key, value);
        }
        return value;
      })
      .finally(() => {
        this.#asking.delete(key);
      });
    this.#asking.set(key, call);

    return call;
  }
}
