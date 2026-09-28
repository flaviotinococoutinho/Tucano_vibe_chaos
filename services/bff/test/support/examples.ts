import { readFileSync } from 'node:fs';

const EXAMPLES = new URL('../../../../contracts/http/bff/examples/', import.meta.url);

/** An example of the contract, as the web would receive it. */
export function example(name: string): unknown {
  return JSON.parse(readFileSync(new URL(name, EXAMPLES), 'utf8'));
}

/** What a value becomes on the wire: undefined members gone, exactly like the response. */
export function wire(value: unknown): unknown {
  return JSON.parse(JSON.stringify(value));
}
