/**
 * Time as the simulator sees it. Every simulated delay goes through a Clock, so tests
 * can check the timing without spending it.
 */
export type Clock = {
  /** Milliseconds since the Unix epoch. */
  now(): number;
  /** Resolves after `ms`, or rejects with the signal's reason as soon as it aborts. */
  sleep(ms: number, signal: AbortSignal): Promise<void>;
};

export const systemClock: Clock = {
  now: () => Date.now(),
  sleep,
};

// Built on the global setTimeout, the one node:test mock timers replace.
function sleep(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    signal.throwIfAborted();
    const cancel = (): void => {
      clearTimeout(timer);
      reject(signal.reason);
    };
    const timer = setTimeout(() => {
      signal.removeEventListener('abort', cancel);
      resolve();
    }, ms);
    signal.addEventListener('abort', cancel, { once: true });
  });
}
