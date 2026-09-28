/** Money as the services send it: minor units and an ISO 4217 code. */
export type Price = { readonly amount: number; readonly currency: string };

/**
 * A service answered something its contract does not allow. That is a bug on one of
 * the two sides, not news for the customer: it becomes a 500 and a log line.
 */
export class UpstreamContractBroken extends Error {
  constructor(where: string, what: string) {
    super(`${where} answered ${what}`);
    this.name = 'UpstreamContractBroken';
  }
}

/**
 * Reads the JSON of a service without trusting it. Every value comes out typed, or the
 * read fails naming the call and the field, like EventFields does for events in PHP.
 */
export class Fields {
  readonly #data: Readonly<Record<string, unknown>>;
  readonly #where: string;
  readonly #prefix: string;

  constructor(data: unknown, where: string, prefix = '') {
    if (!isRecord(data)) {
      throw new UpstreamContractBroken(where, `${prefix || 'a body'} that is not a JSON object`);
    }
    this.#data = data;
    this.#where = where;
    this.#prefix = prefix;
  }

  text(name: string): string {
    const value = this.#data[name];
    if (typeof value === 'string' && value !== '') {
      return value;
    }
    throw this.#broken(name, 'a non-empty string');
  }

  /** Missing and null both read as null: an older service may not send the field yet. */
  optionalText(name: string): string | null {
    const value = this.#data[name];
    if (value === undefined || value === null) {
      return null;
    }
    if (typeof value === 'string' && value !== '') {
      return value;
    }
    throw this.#broken(name, 'a string or null');
  }

  integer(name: string): number {
    const value = this.#data[name];
    if (typeof value === 'number' && Number.isSafeInteger(value)) {
      return value;
    }
    throw this.#broken(name, 'an integer');
  }

  optionalInteger(name: string): number | null {
    return this.#data[name] === undefined || this.#data[name] === null ? null : this.integer(name);
  }

  /** An RFC 3339 instant, given back in UTC with milliseconds, the way the web gets every instant. */
  instant(name: string): string {
    const at = new Date(this.text(name));
    if (Number.isNaN(at.getTime())) {
      throw this.#broken(name, 'an instant');
    }
    return at.toISOString();
  }

  oneOf<T extends string>(name: string, allowed: readonly T[]): T {
    const value = this.text(name);
    if (isOneOf(value, allowed)) {
      return value;
    }
    throw this.#broken(name, `one of ${allowed.join(', ')}`);
  }

  optionalOneOf<T extends string>(name: string, allowed: readonly T[]): T | null {
    return this.optionalText(name) === null ? null : this.oneOf(name, allowed);
  }

  price(name: string): Price {
    const price = this.object(name);
    return { amount: price.integer('amount'), currency: price.text('currency') };
  }

  object(name: string): Fields {
    return new Fields(this.#data[name], this.#where, `${this.#prefix}${name}.`);
  }

  objects(name: string): Fields[] {
    const value = this.#data[name];
    if (!Array.isArray(value)) {
      throw this.#broken(name, 'a list');
    }
    return value.map(
      (item, index) => new Fields(item, this.#where, `${this.#prefix}${name}.${index}.`),
    );
  }

  #broken(name: string, expected: string): UpstreamContractBroken {
    return new UpstreamContractBroken(
      this.#where,
      `${this.#prefix}${name} that is not ${expected}`,
    );
  }
}

function isRecord(value: unknown): value is Readonly<Record<string, unknown>> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function isOneOf<T extends string>(value: string, allowed: readonly T[]): value is T {
  return (allowed as readonly string[]).includes(value);
}
