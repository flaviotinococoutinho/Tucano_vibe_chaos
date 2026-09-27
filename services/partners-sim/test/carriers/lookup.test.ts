import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  carriersApp,
  findPickups,
  getPickup,
  OWN_FLEET_PICKUP,
  PICKUP,
  postPickup,
} from '../support/carriers.ts';
import { WebhookReceiver } from '../support/receiver.ts';

describe('reading a pickup', () => {
  // A GET right after the POST is not a safe way to catch a pickup still "scheduled": with
  // the instant clock the journey runs as many event loop turns as it needs, and a second
  // inject() gives it plenty. The POST response itself already answers 201 with "scheduled"
  // (see booking a pickup, in bookings.test.ts), which is deterministic because bookPickup
  // never awaits anything before it returns.
  it('shows a pickup as delivered once its journey finishes', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = carriersApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const { id } = (await postPickup(app, { body: OWN_FLEET_PICKUP })).json();
    // Own fleet skips the hubs: picked_up, out_for_delivery, delivered.
    await receiver.waitFor(3);

    const response = await getPickup(app, id);

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), {
      id,
      carrier: OWN_FLEET_PICKUP.carrier,
      reference: OWN_FLEET_PICKUP.reference,
      trackingCode: OWN_FLEET_PICKUP.trackingCode,
      status: 'delivered',
      attempts: 1,
      createdAt: '2026-09-27T12:00:00.000Z',
    });
  });

  it('answers 404 with a problem for a pickup it does not know', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    const response = await app.inject({
      method: 'GET',
      url: '/carriers/v1/pickups/pk_nothing',
      headers: { 'x-correlation-id': 'req-1#2' },
    });

    assert.equal(response.statusCode, 404);
    assert.match(String(response.headers['content-type']), /^application\/problem\+json/);
    assert.deepEqual(response.json(), {
      type: 'about:blank',
      title: 'Not Found',
      status: 404,
      detail: 'Pickup pk_nothing does not exist.',
      instance: '/carriers/v1/pickups/pk_nothing',
      correlationId: 'req-1#2',
    });
  });
});

describe('pickups by reference', () => {
  it('finds the pickup of a merchant reference, for a merchant that lost the id', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    const { id } = (await postPickup(app)).json();

    const response = await findPickups(app, PICKUP.reference);

    assert.equal(response.statusCode, 200);
    assert.deepEqual(
      response.json().data.map((pickup: { id: string }) => pickup.id),
      [id],
    );
  });

  it('finds a pickup by the longest reference a pickup accepts', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    const reference = 'r'.repeat(128);
    const { id } = (await postPickup(app, { body: { ...PICKUP, reference } })).json();

    const response = await findPickups(app, reference);

    assert.equal(response.json().data[0]?.id, id);
  });

  it('answers an empty list for a reference it never saw', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    const response = await findPickups(app, 'unknown');

    assert.equal(response.statusCode, 200);
    assert.deepEqual(response.json(), { data: [] });
  });

  it('needs the reference', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    const response = await app.inject({ method: 'GET', url: '/carriers/v1/pickups' });

    assert.equal(response.statusCode, 422);
    assert.ok(response.json().errors.reference);
  });
});
