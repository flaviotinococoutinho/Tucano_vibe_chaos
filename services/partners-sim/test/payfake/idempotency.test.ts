import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { InstantClock } from '../support/clock.ts';
import { CHARGE, getCharge, type LogLine, payfakeApp, postCharge } from '../support/payfake.ts';
import { WebhookReceiver } from '../support/receiver.ts';

describe('idempotency', () => {
  it('answers the same key and body with the first answer, marked as a replay', async (t) => {
    const logs: LogLine[] = [];
    const app = payfakeApp({ logs });
    t.after(() => app.close());

    const first = await postCharge(app);
    const again = await postCharge(app);

    assert.equal(again.statusCode, 201);
    assert.equal(again.headers['idempotent-replayed'], 'true');
    assert.equal(first.headers['idempotent-replayed'], undefined);
    assert.deepEqual(again.json(), first.json());
    assert.equal(logs.filter((line) => line.message === 'charge created').length, 1);
  });

  it('replays the answer as it was, even after the charge settled', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(1);

    const replay = await postCharge(app);

    assert.equal(replay.json().status, 'processing');
    assert.equal((await getCharge(app, id)).json().status, 'succeeded');
  });

  it('matches bodies whatever the order of their fields', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());
    const reordered = {
      reference: CHARGE.reference,
      cardToken: CHARGE.cardToken,
      amount: { currency: 'BRL', value: 18990 },
    };

    const first = await postCharge(app);
    const again = await postCharge(app, { body: reordered });

    assert.equal(again.headers['idempotent-replayed'], 'true');
    assert.equal(again.json().id, first.json().id);
  });

  it('refuses the same key with another body', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());
    await postCharge(app);

    const response = await postCharge(app, {
      body: { ...CHARGE, amount: { value: 1, currency: 'BRL' } },
    });

    assert.equal(response.statusCode, 422);
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Unprocessable Content',
      detail: 'The Idempotency-Key "pay-1" was already used with a different request.',
    });
  });

  it('requires the key, and checks it before the body', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());

    for (const headers of [{}, { 'idempotency-key': '  ' }]) {
      const response = await app.inject({
        method: 'POST',
        url: '/payfake/v1/charges',
        headers: { 'x-correlation-id': 'req-2#1', ...headers },
        payload: { amount: 'lots' },
      });

      assert.equal(response.statusCode, 400);
      assert.deepEqual(response.json(), {
        type: 'about:blank',
        title: 'Bad Request',
        status: 400,
        detail: 'The Idempotency-Key header is required.',
        instance: '/payfake/v1/charges',
        correlationId: 'req-2#1',
      });
    }
  });

  it('refuses a key longer than 255 characters', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());

    const response = await postCharge(app, { key: 'k'.repeat(256) });

    assert.equal(response.statusCode, 400);
    assert.partialDeepStrictEqual(response.json(), {
      detail: 'The Idempotency-Key header must have at most 255 characters.',
    });
  });

  it('forgets a key after 24 hours', async (t) => {
    const clock = new InstantClock();
    const app = payfakeApp({ clock });
    t.after(() => app.close());
    const first = await postCharge(app);

    clock.advance(24 * 60 * 60 * 1000);
    const later = await postCharge(app);

    assert.equal(later.statusCode, 201);
    assert.equal(later.headers['idempotent-replayed'], undefined);
    assert.notEqual(later.json().id, first.json().id);
  });
});
