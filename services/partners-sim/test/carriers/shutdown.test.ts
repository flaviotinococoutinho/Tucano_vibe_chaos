import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { setTimeout as sleep } from 'node:timers/promises';
import { systemClock } from '../../src/clock.ts';
import { carriersApp, OWN_FLEET_PICKUP, postPickup } from '../support/carriers.ts';
import { WebhookReceiver } from '../support/receiver.ts';

describe('shutdown', () => {
  it('stops a journey in progress: no step outlives the server that started it', async (t) => {
    // The first attempt of the first webhook fails, so a retry (1 s backoff) is left pending
    // when the server closes; a step delay far shorter than that isolates the effect.
    const receiver = await WebhookReceiver.start([500]);
    t.after(() => receiver.close());
    const app = carriersApp({
      webhookUrl: receiver.url,
      clock: systemClock,
      env: { CARRIERS_STEP_MIN_MS: '5', CARRIERS_STEP_MAX_MS: '5' },
    });

    await postPickup(app, { body: OWN_FLEET_PICKUP });
    await receiver.waitFor(1);
    await app.close();
    const receivedAtClose = receiver.received.length;
    // Long enough for the pending retry to have gone out, had the shutdown not cancelled it.
    await sleep(1_100);

    assert.equal(receiver.received.length, receivedAtClose);
  });

  it('closes without hanging even while a journey is still walking', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = carriersApp({
      webhookUrl: receiver.url,
      clock: systemClock,
      env: { CARRIERS_STEP_MIN_MS: '50', CARRIERS_STEP_MAX_MS: '50' },
    });

    await postPickup(app, { body: OWN_FLEET_PICKUP });
    // Closes well before the own fleet's three steps (picked_up, out_for_delivery, delivered)
    // could finish: close() must not wait for the journey to complete.
    await app.close();
  });
});
