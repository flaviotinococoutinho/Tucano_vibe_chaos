import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { setImmediate } from 'node:timers/promises';
import { systemClock } from '../../src/clock.ts';
import { verify } from '../../src/payfake/signature.ts';
import { InstantClock } from '../support/clock.ts';
import {
  CHARGE,
  chaosDecisions,
  getCharge,
  type LogLine,
  payfakeApp,
  postCharge,
  putChaos,
  SECRET,
} from '../support/payfake.ts';
import { WebhookReceiver } from '../support/receiver.ts';

const EVENT_ID = /^evt_[0-9A-HJKMNP-TV-Z]{26}$/;

describe('settlement', () => {
  it('approves any other card and tells commerce with a signed charge.succeeded', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const clock = new InstantClock();
    const app = payfakeApp({ webhookUrl: receiver.url, clock });
    t.after(() => app.close());

    const created = await postCharge(app, { headers: { 'x-correlation-id': 'req-3#1' } });
    const [webhook] = await receiver.waitFor(1);

    assert.ok(webhook);
    const event = JSON.parse(webhook.body);
    assert.match(event.id, EVENT_ID);
    // The processing delay is drawn from 300 to 1500 ms, and a draw of 0.5 lands on 900.
    assert.deepEqual(event, {
      id: event.id,
      type: 'charge.succeeded',
      createdAt: '2026-09-27T12:00:00.900Z',
      data: { chargeId: created.json().id, reference: CHARGE.reference, amount: CHARGE.amount },
    });
    const header = String(webhook.headers['payfake-signature']);
    const now = Math.floor(clock.now() / 1000);
    assert.equal(verify({ secret: SECRET, payload: webhook.body, header, now }), 'valid');
    assert.equal(webhook.headers['x-correlation-id'], 'req-3#1');
  });

  const magicTokens = [
    ['tok_decline', 'card_declined'],
    ['tok_insufficient', 'insufficient_funds'],
  ] as const;

  for (const [cardToken, failureCode] of magicTokens) {
    it(`fails a charge paid with ${cardToken} as ${failureCode}`, async (t) => {
      const receiver = await WebhookReceiver.start();
      t.after(() => receiver.close());
      const app = payfakeApp({ webhookUrl: receiver.url });
      t.after(() => app.close());

      const { id } = (await postCharge(app, { body: { ...CHARGE, cardToken } })).json();
      const [webhook] = await receiver.waitFor(1);

      assert.partialDeepStrictEqual(JSON.parse(String(webhook?.body)), {
        type: 'charge.failed',
        data: { chargeId: id, failureCode },
      });
      assert.partialDeepStrictEqual((await getCharge(app, id)).json(), {
        status: 'failed',
        failureCode,
      });
    });
  }

  it('declines at the decline rate, with the charge id in the log', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const logs: LogLine[] = [];
    const app = payfakeApp({ webhookUrl: receiver.url, random: () => 0.4, logs });
    t.after(() => app.close());
    await putChaos(app, { declineRate: 0.5 });

    const { id } = (await postCharge(app)).json();
    const [webhook] = await receiver.waitFor(1);

    assert.partialDeepStrictEqual(JSON.parse(String(webhook?.body)), {
      type: 'charge.failed',
      data: { chargeId: id, failureCode: 'card_declined' },
    });
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      { level: 'info', chaos: 'decline', chargeId: id, message: 'chaos: declining the charge' },
    ]);
  });

  it('approves when the roll lands above the decline rate', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url, random: () => 0.6 });
    t.after(() => app.close());
    await putChaos(app, { declineRate: 0.5 });

    await postCharge(app);
    const [webhook] = await receiver.waitFor(1);

    assert.equal(JSON.parse(String(webhook?.body)).type, 'charge.succeeded');
  });

  it('draws the processing delay between the configured bounds', async (t) => {
    const clock = new InstantClock();
    const env = { PAYFAKE_PROCESSING_MIN_MS: '100', PAYFAKE_PROCESSING_MAX_MS: '200' };
    const app = payfakeApp({ clock, env });
    t.after(() => app.close());

    await postCharge(app);

    assert.equal(clock.sleeps[0], 150);
  });

  it('keeps a charge processing until its processing delay is over', async (t) => {
    // A draw of 0 gives the shortest delay, 300 ms, on the real clock driven by mock timers.
    const app = payfakeApp({ clock: systemClock, random: () => 0 });
    t.after(() => app.close());
    await app.ready();
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now: Date.parse('2026-09-27T12:00:00Z') });

    const { id, createdAt } = (await postCharge(app)).json();
    t.mock.timers.tick(299);
    await setImmediate();
    const before = (await getCharge(app, id)).json();
    t.mock.timers.tick(1);
    await setImmediate();
    const after = (await getCharge(app, id)).json();

    assert.equal(createdAt, '2026-09-27T12:00:00.000Z');
    assert.equal(before.status, 'processing');
    assert.equal(after.status, 'succeeded');
  });
});
