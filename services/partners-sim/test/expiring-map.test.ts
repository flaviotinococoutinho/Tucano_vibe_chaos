import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { ExpiringMap } from '../src/expiring-map.ts';
import { InstantClock } from './support/clock.ts';

const HOUR_MS = 60 * 60 * 1000;

function mapWith(maxEntries = 10) {
  const clock = new InstantClock();
  const map = new ExpiringMap<string>(clock, { ttlMs: 24 * HOUR_MS, maxEntries });

  return { clock, map };
}

describe('expiring map', () => {
  it('forgets an entry once its time is up', () => {
    const { clock, map } = mapWith();
    map.set('pay-1', 'first');

    clock.advance(24 * HOUR_MS - 1);
    assert.equal(map.get('pay-1'), 'first');
    clock.advance(1);
    assert.equal(map.get('pay-1'), undefined);
  });

  it('keeps the first expiry when an entry changes', () => {
    const { clock, map } = mapWith();
    map.set('ch_1', 'processing');
    clock.advance(23 * HOUR_MS);
    map.set('ch_1', 'succeeded');

    assert.equal(map.get('ch_1'), 'succeeded');
    clock.advance(HOUR_MS);
    assert.equal(map.get('ch_1'), undefined);
  });

  it('gives an expired key a new life when it is stored again', () => {
    const { clock, map } = mapWith();
    map.set('pay-1', 'first');
    clock.advance(24 * HOUR_MS);
    map.set('pay-1', 'second');

    clock.advance(23 * HOUR_MS);
    assert.equal(map.get('pay-1'), 'second');
  });

  it('drops the oldest entry when it grows past its limit', () => {
    const { map } = mapWith(2);
    map.set('ch_1', 'first');
    map.set('ch_2', 'second');
    map.set('ch_3', 'third');

    assert.deepEqual(
      ['ch_1', 'ch_2', 'ch_3'].map((key) => map.get(key)),
      [undefined, 'second', 'third'],
    );
  });
});
