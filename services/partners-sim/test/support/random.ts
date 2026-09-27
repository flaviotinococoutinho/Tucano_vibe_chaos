import type { Random } from '../../src/chance.ts';

/**
 * A `Random` that hands out `values` in order, one per call, so a test can steer a journey
 * through several dice rolls (a failed visit, then a delivered one...). Throws once the
 * values run out, so an unexpected extra roll fails loudly instead of reusing a stale one.
 */
export function sequence(...values: readonly number[]): Random {
  const queue = [...values];

  return () => {
    const next = queue.shift();
    if (next === undefined) {
      throw new Error('The random sequence ran out: the test did not plan for this many rolls.');
    }

    return next;
  };
}
