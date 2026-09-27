import assert from 'node:assert/strict';
import { describe, it, type TestContext } from 'node:test';
import Fastify from 'fastify';
import type { Clock } from '../../src/clock.ts';
import { systemClock } from '../../src/clock.ts';
import { loadConfig } from '../../src/config.ts';
import { CALM, Chaos, type ChaosSettings } from '../../src/payfake/chaos.ts';
import { verify } from '../../src/payfake/signature.ts';
import { type Origin, type WebhookEvent, Webhooks } from '../../src/payfake/webhooks.ts';
import { logOptions } from '../../src/platform/logging.ts';
import { InstantClock } from '../support/clock.ts';
import { chaosDecisions, type LogLine, SECRET } from '../support/payfake.ts';
import { type Answer, WebhookReceiver } from '../support/receiver.ts';

const EVENT: WebhookEvent = {
  id: 'evt_01J8Z5W3Q4X9M2N7B8C6D5E4F3',
  type: 'charge.succeeded',
  createdAt: '2026-09-27T12:00:00.900Z',
  data: {
    chargeId: 'ch_01J8Z5W3Q4X9M2N7B8C6D5E4F3',
    reference: 'pay-1',
    amount: { value: 18990, currency: 'BRL' },
  },
};

type Setup = {
  readonly answers?: readonly Answer[];
  readonly clock?: Clock;
  readonly webhooks?: Partial<ChaosSettings['webhooks']>;
};

async function webhooksFor(
  t: TestContext,
  { answers, clock = new InstantClock(), webhooks }: Setup,
) {
  const receiver = await WebhookReceiver.start(answers);
  t.after(() => receiver.close());
  const chaos = new Chaos(() => 0.5);
  chaos.change({ ...CALM, webhooks: { ...CALM.webhooks, ...webhooks } });
  const shutdown = new AbortController();
  const target = { url: receiver.url, secret: SECRET };
  const logs: LogLine[] = [];
  const logger = logOptions(loadConfig({ LOG_LEVEL: 'info' }), {
    write: (line) => logs.push(JSON.parse(line)),
  });
  // The logger of the request that settled the charge, like request.log in the app.
  const log = Fastify({ logger }).log.child({ correlation_id: 'req-7#1' });
  const origin: Origin = { log, correlationId: 'req-7#1' };

  return {
    receiver,
    logs,
    shutdown,
    publish: () =>
      new Webhooks({ target, clock, chaos, signal: shutdown.signal }).publish(EVENT, origin),
  };
}

describe('webhook delivery', () => {
  it('posts the event as JSON, signed, with the correlation id of the request behind it', async (t) => {
    const clock = new InstantClock();
    const { receiver, publish } = await webhooksFor(t, { clock });

    assert.deepEqual(await publish(), [{ outcome: 'delivered', attempts: 1 }]);

    const [webhook] = receiver.received;
    assert.ok(webhook);
    assert.deepEqual(JSON.parse(webhook.body), EVENT);
    assert.equal(webhook.headers['content-type'], 'application/json');
    assert.equal(webhook.headers['x-correlation-id'], 'req-7#1');
    const header = String(webhook.headers['payfake-signature']);
    const now = Math.floor(clock.now() / 1000);
    assert.equal(verify({ secret: SECRET, payload: webhook.body, header, now }), 'valid');
  });

  it('retries answers outside 2xx with exponential backoff until one is accepted', async (t) => {
    const clock = new InstantClock();
    const { receiver, publish } = await webhooksFor(t, { clock, answers: [500, 503, 302] });

    assert.deepEqual(await publish(), [{ outcome: 'delivered', attempts: 4 }]);

    assert.deepEqual(clock.sleeps, [1_000, 2_000, 4_000]);
    assert.equal(receiver.received.length, 4);
    assert.ok(receiver.received.every(({ body }) => JSON.parse(body).id === EVENT.id));
  });

  it('retries network errors the same way', async (t) => {
    const clock = new InstantClock();
    const { receiver, publish } = await webhooksFor(t, { clock, answers: ['hang-up', 'hang-up'] });

    assert.deepEqual(await publish(), [{ outcome: 'delivered', attempts: 3 }]);

    assert.deepEqual(clock.sleeps, [1_000, 2_000]);
    assert.equal(receiver.received.length, 1);
  });

  it('gives up after five retries, 31 seconds after the first attempt', async (t) => {
    const clock = new InstantClock();
    const answers = Array.from({ length: 6 }, () => 500);
    const { receiver, logs, publish } = await webhooksFor(t, { clock, answers });

    assert.deepEqual(await publish(), [{ outcome: 'abandoned', attempts: 6 }]);

    assert.deepEqual(clock.sleeps, [1_000, 2_000, 4_000, 8_000, 16_000]);
    assert.equal(receiver.received.length, 6);
    assert.partialDeepStrictEqual(logs.at(-1), {
      level: 'error',
      message: 'webhook abandoned after the last retry',
      eventId: EVENT.id,
      attempt: 6,
      failure: 'HTTP 500',
      correlation_id: 'req-7#1',
    });
  });

  it('stops retrying at once when the server shuts down', async (t) => {
    const { receiver, shutdown, publish } = await webhooksFor(t, {
      clock: systemClock,
      answers: [500],
    });

    const delivery = publish();
    await receiver.waitFor(1);
    shutdown.abort();

    await assert.rejects(delivery, { name: 'AbortError' });
    assert.equal(receiver.received.length, 1);
  });

  it('drops the webhook when the drop rate says so, and logs it', async (t) => {
    const { receiver, logs, publish } = await webhooksFor(t, { webhooks: { dropRate: 0.6 } });

    assert.deepEqual(await publish(), []);

    assert.equal(receiver.received.length, 0);
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      {
        level: 'info',
        chaos: 'webhook-drop',
        chargeId: EVENT.data.chargeId,
        eventId: EVENT.id,
        message: 'chaos: dropping the webhook',
      },
    ]);
  });

  it('sends the same event twice when the duplicate rate says so', async (t) => {
    const { receiver, logs, publish } = await webhooksFor(t, { webhooks: { duplicateRate: 0.6 } });

    assert.deepEqual(await publish(), [
      { outcome: 'delivered', attempts: 1 },
      { outcome: 'delivered', attempts: 1 },
    ]);

    const [first, second] = receiver.received.map(({ body }) => body);
    assert.equal(first, second);
    assert.equal(JSON.parse(String(first)).id, EVENT.id);
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      { chaos: 'webhook-duplicate', chargeId: EVENT.data.chargeId, eventId: EVENT.id },
    ]);
  });

  it('leaves a rate below the roll alone', async (t) => {
    const { receiver, logs, publish } = await webhooksFor(t, {
      webhooks: { dropRate: 0.4, duplicateRate: 0.4 },
    });

    assert.deepEqual(await publish(), [{ outcome: 'delivered', attempts: 1 }]);

    assert.equal(receiver.received.length, 1);
    assert.deepEqual(chaosDecisions(logs), []);
  });

  it('holds every webhook back by the configured delay', async (t) => {
    const clock = new InstantClock();
    const { receiver, logs, publish } = await webhooksFor(t, {
      clock,
      webhooks: { delayMs: 5_000 },
    });

    assert.deepEqual(await publish(), [{ outcome: 'delivered', attempts: 1 }]);

    assert.deepEqual(clock.sleeps, [5_000]);
    assert.equal(receiver.received.length, 1);
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      { chaos: 'webhook-delay', chargeId: EVENT.data.chargeId, delayMs: 5_000 },
    ]);
  });
});
