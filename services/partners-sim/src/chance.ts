/** A number from 0 (included) to 1 (excluded), like `Math.random`. Tests pass a fixed one. */
export type Random = () => number;

/** Whether something with this probability happens. A rate of 0 never draws a number. */
export function happens(random: Random, rate: number): boolean {
  return rate > 0 && random() < rate;
}

/** A whole number from `min` to `max`, both included. */
export function between(random: Random, min: number, max: number): number {
  return min + Math.floor(random() * (max - min + 1));
}

/** One item from a non-empty list, drawn the same way as `between`. */
export function pick<T>(random: Random, items: readonly [T, ...T[]]): T {
  const [first] = items;

  return items[between(random, 0, items.length - 1)] ?? first;
}
