import assert from 'node:assert/strict';
import { describe, it, type TestContext } from 'node:test';
import Fastify from 'fastify';
import type { Clock } from '../../src/clock.ts';
import { systemClock } from '../../src/clock.ts';
import { loadConfig } from '../../src/config.ts';
import { logOptions } from '../../src/platform/logging.ts';
import type { WebhookPlan } from '../../src/webhooks/chaos-plan.ts';
import { type Origin, type WebhookEvent, Webhooks } from '../../src/webhooks/sender.ts';
import { verify } from '../../src/webhooks/signature.ts';
import { InstantClock } from '../support/clock.ts';
import type { LogLine } from '../support/logs.ts';
import { type Answer, WebhookReceiver } from '../support/receiver.ts';

const SECRET = 'whsec_test';
const HEADER = 'Test-Signature';

const SENT: WebhookPlan = { fate: 'sent', copies: 1, delayMs: 0 };

const EVENT: WebhookEvent<{ readonly subjectId: string }> = {
  id: 'evt_01J8Z5W3Q4X9M2N7B8C6D5E4F3',
  type: 'thing.happened',
  createdAt: '2026-09-27T12:00:00.900Z',
  data: { subjectId: 'thing_1' },
};

type Setup = {
  readonly answers?: readonly Answer[];
  readonly clock?: Clock;
  readonly plan?: WebhookPlan;
};

async function webhooksFor(
  t: TestContext,
  { answers, clock = new InstantClock(), plan = SENT }: Setup,
) {
  const receiver = await WebhookReceiver.start(answers);
  t.after(() => receiver.close());
  const shutdown = new AbortController();
  const target = { url: receiver.url, secret: SECRET };
  const logs: LogLine[] = [];
  const logger = logOptions(loadConfig({ LOG_LEVEL: 'info' }), {
    write: (line) => logs.push(JSON.parse(line)),
  });
  // The logger of the request that triggered the event, like request.log in the app.
  const log = Fastify({ logger }).log.child({ correlation_id: 'req-7#1' });
  const origin: Origin = { log, correlationId: 'req-7#1' };
  const webhooks = new Webhooks({
    target,
    signatureHeader: HEADER,
    delivery: loadConfig({}).webhooks,
    clock,
    plan: () => plan,
    signal: shutdown.signal,
  });

  return { receiver, logs, shutdown, publish: () => webhooks.publish(EVENT, origin) };
}

describe('webhook sender', () => {
  it('posts the event as JSON, signed under the given header, with the correlation id behind it', async (t) => {
    const clock = new InstantClock();
    const { receiver, publish } = await webhooksFor(t, { clock });

    assert.deepEqual(await publish(), [{ outcome: 'delivered', attempts: 1 }]);

    const [webhook] = receiver.received;
    assert.ok(webhook);
    assert.deepEqual(JSON.parse(webhook.body), EVENT);
    assert.equal(webhook.headers['content-type'], 'application/json');
    assert.equal(webhook.headers['x-correlation-id'], 'req-7#1');
    const header = String(webhook.headers['test-signature']);
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
      type: EVENT.type,
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

  it('never sends an event the plan drops', async (t) => {
    const { receiver, publish } = await webhooksFor(t, { plan: { fate: 'dropped' } });

    assert.deepEqual(await publish(), []);

    assert.equal(receiver.received.length, 0);
  });

  it('sends the event twice when the plan says two copies', async (t) => {
    const { receiver, publish } = await webhooksFor(t, {
      plan: { fate: 'sent', copies: 2, delayMs: 0 },
    });

    assert.deepEqual(await publish(), [
      { outcome: 'delivered', attempts: 1 },
      { outcome: 'delivered', attempts: 1 },
    ]);

    const [first, second] = receiver.received.map(({ body }) => body);
    assert.equal(first, second);
    assert.equal(JSON.parse(String(first)).id, EVENT.id);
  });

  it('waits the planned delay before the first attempt', async (t) => {
    const clock = new InstantClock();
    const { receiver, publish } = await webhooksFor(t, {
      clock,
      plan: { fate: 'sent', copies: 1, delayMs: 5_000 },
    });

    assert.deepEqual(await publish(), [{ outcome: 'delivered', attempts: 1 }]);

    assert.deepEqual(clock.sleeps, [5_000]);
    assert.equal(receiver.received.length, 1);
  });
});
