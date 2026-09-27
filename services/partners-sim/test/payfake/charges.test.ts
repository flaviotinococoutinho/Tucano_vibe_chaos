import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  CHARGE,
  CHARGE_ID,
  getCharge,
  type LogLine,
  payfakeApp,
  postCharge,
} from '../support/payfake.ts';
import { WebhookReceiver } from '../support/receiver.ts';

describe('charges', () => {
  it('accepts a charge as processing and answers 201 with it', async (t) => {
    const logs: LogLine[] = [];
    const app = payfakeApp({ logs });
    t.after(() => app.close());

    const response = await postCharge(app, { headers: { 'x-correlation-id': 'req-1#1' } });

    assert.equal(response.statusCode, 201);
    assert.equal(response.headers['idempotent-replayed'], undefined);
    const charge = response.json();
    assert.match(charge.id, CHARGE_ID);
    assert.deepEqual(charge, {
      id: charge.id,
      status: 'processing',
      amount: CHARGE.amount,
      reference: CHARGE.reference,
      createdAt: '2026-09-27T12:00:00.000Z',
    });
    assert.partialDeepStrictEqual(
      logs.find((line) => line.message === 'charge created'),
      { chargeId: charge.id, reference: CHARGE.reference, correlation_id: 'req-1#1' },
    );
  });

  it('shows a charge as it is now, which is how reconciliation reads it', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = payfakeApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (await postCharge(app)).json();
    await receiver.waitFor(1);

    const response = await getCharge(app, id);

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), {
      id,
      status: 'succeeded',
      amount: CHARGE.amount,
      reference: CHARGE.reference,
      createdAt: '2026-09-27T12:00:00.000Z',
    });
  });

  it('answers 404 with a problem for a charge it does not know', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());

    const response = await app.inject({
      method: 'GET',
      url: '/payfake/v1/charges/ch_nothing',
      headers: { 'x-correlation-id': 'req-1#2' },
    });

    assert.equal(response.statusCode, 404);
    assert.match(String(response.headers['content-type']), /^application\/problem\+json/);
    assert.deepEqual(response.json(), {
      type: 'about:blank',
      title: 'Not Found',
      status: 404,
      detail: 'Charge ch_nothing does not exist.',
      instance: '/payfake/v1/charges/ch_nothing',
      correlationId: 'req-1#2',
    });
  });

  it('refuses a body it cannot charge, and names the field', async (t) => {
    const app = payfakeApp();
    t.after(() => app.close());
    const { amount, cardToken } = CHARGE;
    const cases: [object, Record<string, string[]>][] = [
      [{ amount, cardToken }, { reference: ["must have required property 'reference'"] }],
      [{ ...CHARGE, amount: { ...amount, value: 0 } }, { 'amount.value': ['must be >= 1'] }],
      [
        { ...CHARGE, amount: { ...amount, value: '18990' } },
        { 'amount.value': ['must be integer'] },
      ],
      [
        { ...CHARGE, amount: { ...amount, currency: 'brl' } },
        { 'amount.currency': ['must match pattern "^[A-Z]{3}$"'] },
      ],
      [
        { ...CHARGE, cardToken: '4111111111111111' },
        { cardToken: ['must match pattern "^tok_\\w{1,60}$"'] },
      ],
      [{ ...CHARGE, reference: '' }, { reference: ['must NOT have fewer than 1 characters'] }],
      [{ ...CHARGE, installments: 3 }, { body: ['must NOT have additional properties'] }],
    ];

    for (const [body, errors] of cases) {
      const response = await postCharge(app, { body });

      assert.equal(response.statusCode, 422, JSON.stringify(body));
      assert.partialDeepStrictEqual(response.json(), { title: 'Unprocessable Content', errors });
    }
  });
});
