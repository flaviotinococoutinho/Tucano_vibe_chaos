import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { FastifyInstance } from 'fastify';
import { systemClock } from '../../src/clock.ts';
import type { Money } from '../../src/payfake/charge.ts';
import { CHARGE, getCharge, payfakeApp, postCharge } from '../support/payfake.ts';
import { WebhookReceiver } from '../support/receiver.ts';

const REFUND_ID = /^re_[0-9A-HJKMNP-TV-Z]{26}$/;

type RefundInput = { readonly key?: string; readonly amount?: Money };

function postRefund(
  app: FastifyInstance,
  chargeId: string,
  { key = 'refund-1', amount = CHARGE.amount }: RefundInput = {},
) {
  return app.inject({
    method: 'POST',
    url: `/payfake/v1/charges/${chargeId}/refunds`,
    headers: { 'idempotency-key': key },
    payload: { amount },
  });
}

describe('refunds', () => {
  it('refunds a succeeded charge: 201 processing now, refund.succeeded by webhook later', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(1);

    const response = await postRefund(app, id);
    const [, webhook] = await receiver.waitFor(2);

    assert.equal(response.statusCode, 201);
    const refund = response.json();
    assert.match(refund.id, REFUND_ID);
    assert.deepEqual(refund, {
      id: refund.id,
      chargeId: id,
      status: 'processing',
      amount: CHARGE.amount,
      createdAt: '2026-09-27T12:00:00.900Z',
    });
    assert.partialDeepStrictEqual(JSON.parse(String(webhook?.body)), {
      type: 'refund.succeeded',
      data: { chargeId: id, reference: CHARGE.reference, amount: CHARGE.amount },
    });
    assert.partialDeepStrictEqual((await getCharge(app, id)).json(), {
      status: 'refunded',
      refund: { ...refund, status: 'succeeded' },
    });
  });

  it('replays a refund for the same key', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(1);

    const first = await postRefund(app, id);
    const again = await postRefund(app, id);

    assert.equal(again.statusCode, 201);
    assert.equal(again.headers['idempotent-replayed'], 'true');
    assert.deepEqual(again.json(), first.json());
  });

  it('refuses to refund a charge that did not succeed, with 409', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (
      await postCharge(app, { body: { ...CHARGE, cardToken: 'tok_decline' } })
    ).json();
    await receiver.waitFor(1);

    const response = await postRefund(app, id);

    assert.equal(response.statusCode, 409);
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Conflict',
      detail: `Charge ${id} is failed and cannot be refunded.`,
    });
  });

  it('refuses to refund a charge still processing', async (t) => {
    const env = { PAYFAKE_PROCESSING_MIN_MS: '60000', PAYFAKE_PROCESSING_MAX_MS: '60000' };
    const app = payfakeApp({ clock: systemClock, env });
    t.after(() => app.close());
    const { id } = (await postCharge(app)).json();

    const response = await postRefund(app, id);

    assert.equal(response.statusCode, 409);
    assert.equal(response.json().detail, `Charge ${id} is processing and cannot be refunded.`);
  });

  it('refuses a second refund of the same charge', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(1);
    await postRefund(app, id);

    const response = await postRefund(app, id, { key: 'refund-2' });

    assert.equal(response.statusCode, 409);
    assert.equal(response.json().detail, `Charge ${id} is refunded and cannot be refunded.`);
  });

  it('refuses a partial refund, and keeps no answer for it under the key', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(1);

    const partial = await postRefund(app, id, { amount: { value: 1000, currency: 'BRL' } });
    const full = await postRefund(app, id);

    assert.equal(partial.statusCode, 422);
    assert.equal(
      partial.json().detail,
      `Only full refunds are supported: refund 18990 BRL for charge ${id}.`,
    );
    assert.equal(full.statusCode, 201);
    assert.equal(full.headers['idempotent-replayed'], undefined);
  });

  it('answers 404 for a charge it does not know', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());

    const response = await postRefund(app, 'ch_nothing');

    assert.equal(response.statusCode, 404);
    assert.equal(response.json().detail, 'Charge ch_nothing does not exist.');
  });

  it('requires an Idempotency-Key', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());

    const response = await app.inject({
      method: 'POST',
      url: '/payfake/v1/charges/ch_nothing/refunds',
      payload: { amount: CHARGE.amount },
    });

    assert.equal(response.statusCode, 400);
    assert.equal(response.json().detail, 'The Idempotency-Key header is required.');
  });
});
