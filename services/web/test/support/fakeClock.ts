import { act } from '@testing-library/react';
import type { Clock } from '../../src/components/LiveDelivery.tsx';

type Scheduled = { readonly id: number; dueAt: number; readonly run: () => void };

export type FakeClock = Clock & {
  /** Runs every callback due within `ms`, in order, including ones a callback schedules
   *  itself (the reconnect loop, the staleness tick), wrapped in `act` so React settles. */
  readonly advance: (ms: number) => void;
};

/**
 * A hand-driven stand-in for `setTimeout`/`Date.now`. Real fake timers (`vi.useFakeTimers`)
 * fight React's own effect scheduling in this suite (see `test/hypermedia/live.test.tsx`), so
 * `LiveDelivery` takes its clock as a prop instead, and a test drives this one by hand.
 */
export function fakeClock(startAt = 0): FakeClock {
  let now = startAt;
  let nextId = 1;
  const scheduled: Scheduled[] = [];

  // The earliest call still due by `target`, or `undefined` once nothing is left to run.
  function nextDue(target: number): Scheduled | undefined {
    return scheduled.reduce<Scheduled | undefined>((earliest, call) => {
      if (call.dueAt > target) {
        return earliest;
      }
      return earliest === undefined || call.dueAt < earliest.dueAt ? call : earliest;
    }, undefined);
  }

  function advance(ms: number): void {
    const target = now + ms;
    act(() => {
      let due = nextDue(target);
      while (due !== undefined) {
        scheduled.splice(scheduled.indexOf(due), 1);
        now = due.dueAt;
        due.run();
        due = nextDue(target);
      }
      now = target;
    });
  }

  return {
    now: () => now,
    setTimeout: (callback, delayMs) => {
      const id = nextId++;
      scheduled.push({ id, dueAt: now + delayMs, run: callback });
      return id;
    },
    clearTimeout: (id) => {
      const index = scheduled.findIndex((call) => call.id === id);
      if (index !== -1) {
        scheduled.splice(index, 1);
      }
    },
    advance,
  };
}
