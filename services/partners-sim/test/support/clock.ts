import type { Clock } from '../../src/clock.ts';

/**
 * A clock that never waits: every sleep returns at once, moves time forward by what it
 * asked for and is recorded, so a test can check each delay without spending it.
 */
export class InstantClock implements Clock {
  readonly sleeps: number[] = [];
  private time = Date.parse('2026-09-27T12:00:00.000Z');

  now(): number {
    return this.time;
  }

  async sleep(ms: number, signal: AbortSignal): Promise<void> {
    signal.throwIfAborted();
    this.sleeps.push(ms);
    this.time += ms;
  }

  /** Time passing with nobody asleep, like the hours between two requests. */
  advance(ms: number): void {
    this.time += ms;
  }
}
