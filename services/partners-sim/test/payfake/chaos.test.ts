import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { systemClock } from '../../src/clock.ts';
import { CALM } from '../../src/payfake/chaos.ts';
import { InstantClock } from '../support/clock.ts';
import { eventually } from '../support/eventually.ts';
import {
  CHARGE,
  chaosDecisions,
  getCharge,
  type LogLine,
  payfakeApp,
  postCharge,
  putChaos,
} from '../support/payfake.ts';
import { WebhookReceiver } from '../support/receiver.ts';

describe('chaos controls', () => {
  it('start calm', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());

    const response = await app.inject({ method: 'GET', url: '/_chaos/payfake' });

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), CALM);
  });

  it('take a whole experiment per PUT: every knob left out goes back to calm', async (t) => {
    const logs: LogLine[] = [];
    const app = payfakeApp({ logs });
    t.after(() => app.close());
    await putChaos(app, { declineRate: 0.2 });

    const response = await putChaos(app, {
      latencyMs: { min: 100, max: 800 },
      errorRate: 0.3,
      webhooks: { dropRate: 0.5 },
    });

    const expected = {
      ...CALM,
      latencyMs: { min: 100, max: 800 },
      errorRate: 0.3,
      webhooks: { ...CALM.webhooks, dropRate: 0.5 },
    };
    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), expected);
    assert.deepEqual(
      (await app.inject({ method: 'GET', url: '/_chaos/payfake' })).json(),
      expected,
    );
    assert.partialDeepStrictEqual(
      logs.findLast((line) => line.settings !== undefined),
      {
        level: 'info',
        message: 'chaos settings changed',
        settings: expected,
      },
    );
  });

  it('go back to calm on DELETE', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());
    await putChaos(app, { errorRate: 1, webhooks: { delayMs: 1000 } });

    const response = await app.inject({ method: 'DELETE', url: '/_chaos/payfake' });

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), CALM);
  });

  it('refuse settings they cannot run with, name the field and keep the old ones', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());
    const cases: [object, Record<string, string[]>][] = [
      [{ errorRate: 1.5 }, { errorRate: ['must be <= 1'] }],
      [{ declineRate: -0.1 }, { declineRate: ['must be >= 0'] }],
      [{ timeoutRate: '0.5' }, { timeoutRate: ['must be number'] }],
      [{ latencyMs: { min: 500, max: 100 } }, { 'latencyMs.max': ['must be >= 500'] }],
      [{ latencyMs: { min: 100 } }, { 'latencyMs.max': ["must have required property 'max'"] }],
      [{ latencyMs: { min: 0, max: 60_000 } }, { 'latencyMs.max': ['must be <= 30000'] }],
      [{ webhooks: { delayMs: 1.5 } }, { 'webhooks.delayMs': ['must be integer'] }],
      [{ errorrate: 0.5 }, { body: ['must NOT have additional properties'] }],
    ];

    for (const [settings, errors] of cases) {
      const response = await putChaos(app, settings);

      assert.equal(response.statusCode, 422, JSON.stringify(settings));
      assert.partialDeepStrictEqual(response.json(), { title: 'Unprocessable Content', errors });
    }
    assert.deepEqual((await app.inject({ method: 'GET', url: '/_chaos/payfake' })).json(), CALM);
  });

  it('fail charge creation with 500 before the charge exists, so a retry is safe', async (t) => {
    const logs: LogLine[] = [];
    const app = payfakeApp({ logs });
    t.after(() => app.close());
    await putChaos(app, { errorRate: 0.6 });

    const failed = await postCharge(app);
    await app.inject({ method: 'DELETE', url: '/_chaos/payfake' });
    const retry = await postCharge(app);

    assert.equal(failed.statusCode, 500);
    assert.partialDeepStrictEqual(failed.json(), {
      title: 'Internal Server Error',
      detail: 'Something went wrong on our side. Quote the correlation id when reporting it.',
    });
    assert.equal(retry.statusCode, 201);
    assert.equal(retry.headers['idempotent-replayed'], undefined);
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      {
        level: 'info',
        chaos: 'error',
        reference: CHARGE.reference,
        message: 'chaos: failing with 500',
      },
    ]);
  });

  it('never fail a replay: the charge exists, and the key answers with it', async (t) => {
    const logs: LogLine[] = [];
    const app = payfakeApp({ logs });
    t.after(() => app.close());
    const first = await postCharge(app);
    await putChaos(app, { errorRate: 1 });

    const replay = await postCharge(app);

    assert.equal(replay.statusCode, 201);
    assert.equal(replay.headers['idempotent-replayed'], 'true');
    assert.deepEqual(replay.json(), first.json());
    assert.deepEqual(chaosDecisions(logs), []);
  });

  it('delay answers by a latency drawn between min and max', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const clock = new InstantClock();
    const logs: LogLine[] = [];
    const app = payfakeApp({ webhookUrl: receiver.url, clock, logs });
    t.after(() => app.close());
    await putChaos(app, { latencyMs: { min: 200, max: 400 } });

    const { id } = (await postCharge(app)).json();
    await getCharge(app, id);

    // The processing delay is asked for first; then each answer waits 300 ms (a draw of 0.5).
    assert.deepEqual(clock.sleeps, [900, 300, 300]);
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      { chaos: 'latency', chargeId: id, delayMs: 300, message: 'chaos: delaying the response' },
      { chaos: 'latency', chargeId: id, delayMs: 300 },
    ]);
  });

  it('hold the answer until the client gives up, and the retry with the key gets it', async (t) => {
    const logs: LogLine[] = [];
    const app = payfakeApp({ clock: systemClock, logs });
    t.after(() => app.close());
    const address = await app.listen({ host: '127.0.0.1', port: 0 });
    await putChaos(app, { timeoutRate: 1 });

    const request = fetch(`${address}/payfake/v1/charges`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'idempotency-key': 'pay-1' },
      body: JSON.stringify(CHARGE),
      signal: AbortSignal.timeout(200),
    });
    await assert.rejects(request, { name: 'TimeoutError' });
    await app.inject({ method: 'DELETE', url: '/_chaos/payfake' });
    const retry = await postCharge(app);

    assert.equal(retry.statusCode, 201);
    assert.equal(retry.headers['idempotent-replayed'], 'true');
    const chargeId = retry.json().id;
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      { chaos: 'timeout', chargeId, message: 'chaos: holding the response' },
    ]);
    await eventually(() => logs.some((line) => line.message === 'client gave up waiting'));
  });

  it('release a held answer after 30 seconds for a client that never gives up', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const clock = new InstantClock();
    const logs: LogLine[] = [];
    const app = payfakeApp({ webhookUrl: receiver.url, clock, logs });
    t.after(() => app.close());
    await putChaos(app, { timeoutRate: 1 });

    const response = await postCharge(app);

    assert.equal(response.statusCode, 201);
    assert.deepEqual(clock.sleeps, [900, 30_000]);
    assert.partialDeepStrictEqual(
      logs.find((line) => line.message === 'held response released'),
      { chargeId: response.json().id, heldMs: 30_000 },
    );
  });

  it('release a held answer when the server shuts down, and close its connection', async (t) => {
    const logs: LogLine[] = [];
    const app = payfakeApp({ clock: systemClock, logs });
    t.after(() => app.close());
    const address = await app.listen({ host: '127.0.0.1', port: 0 });
    await putChaos(app, { timeoutRate: 1 });

    const request = fetch(`${address}/payfake/v1/charges`, {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'idempotency-key': 'pay-1' },
      body: JSON.stringify(CHARGE),
    });
    await eventually(() => chaosDecisions(logs).length > 0);
    await app.close();
    const response = await request;

    assert.equal(response.status, 201);
    // Kept alive, the connection would hold the server open for Fastify's 72 s keep-alive.
    assert.equal(response.headers.get('connection'), 'close');
  });

  it('drops a charge webhook at the drop rate, and logs the charge id', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const logs: LogLine[] = [];
    const app = payfakeApp({ webhookUrl: receiver.url, random: () => 0.4, logs });
    t.after(() => app.close());
    await putChaos(app, { webhooks: { dropRate: 0.5 } });

    const { id } = (await postCharge(app)).json();
    await eventually(() => chaosDecisions(logs).some((line) => line.chaos === 'webhook-drop'));

    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      {
        level: 'info',
        chaos: 'webhook-drop',
        chargeId: id,
        message: 'chaos: dropping the webhook',
      },
    ]);
    assert.equal(receiver.received.length, 0);
  });

  it('sends a charge webhook twice at the duplicate rate', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const logs: LogLine[] = [];
    const app = payfakeApp({ webhookUrl: receiver.url, random: () => 0.4, logs });
    t.after(() => app.close());
    await putChaos(app, { webhooks: { duplicateRate: 0.5 } });

    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(2);

    const [first, second] = receiver.received.map(({ body }) => JSON.parse(body).id);
    assert.equal(first, second);
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      { chaos: 'webhook-duplicate', chargeId: id, message: 'chaos: sending the webhook twice' },
    ]);
  });

  it('delays every charge webhook by the configured amount', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const clock = new InstantClock();
    const logs: LogLine[] = [];
    const app = payfakeApp({ webhookUrl: receiver.url, clock, logs });
    t.after(() => app.close());
    await putChaos(app, { webhooks: { delayMs: 5_000 } });

    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(1);

    assert.ok(clock.sleeps.includes(5_000));
    assert.partialDeepStrictEqual(chaosDecisions(logs), [
      { chaos: 'webhook-delay', chargeId: id, delayMs: 5_000 },
    ]);
  });
});
