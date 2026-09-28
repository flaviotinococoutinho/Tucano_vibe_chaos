import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { setTimeout as sleep } from 'node:timers/promises';
import type { FastifyInstance } from 'fastify';
import {
  carriersApp,
  EVENT_ID,
  getPickup,
  getPickupEvents,
  OWN_FLEET_PICKUP,
  postPickup,
  putChaos,
} from '../support/carriers.ts';
import { WebhookReceiver } from '../support/receiver.ts';

type HistoryEvent = {
  readonly id: string;
  readonly type: string;
  readonly data: { readonly pickupId: string };
};

/** With every webhook dropped there is nothing to wait for on the receiver: the pickup says when it is done. */
async function untilDelivered(app: FastifyInstance, id: string): Promise<void> {
  for (let tries = 1; tries <= 200; tries += 1) {
    if ((await getPickup(app, id)).json().status === 'delivered') {
      return;
    }
    await sleep(10);
  }
  throw new Error('The journey did not finish within two seconds.');
}

describe('the tracking history of a pickup', () => {
  it('keeps every event, also the ones whose webhook was dropped', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = carriersApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    await putChaos(app, { webhooks: { dropRate: 1 } });
    const { id } = (await postPickup(app, { body: OWN_FLEET_PICKUP })).json();
    await untilDelivered(app, id);

    const response = await getPickupEvents(app, id);
    const events: HistoryEvent[] = response.json().data;

    assert.equal(response.statusCode, 200);
    assert.deepEqual(
      events.map((event) => event.type),
      ['parcel.picked_up', 'parcel.out_for_delivery', 'parcel.delivered'],
    );
    assert.ok(events.every((event) => EVENT_ID.test(event.id) && event.data.pickupId === id));
    assert.equal(receiver.received.length, 0, 'Every webhook was dropped on the way.');
  });

  it('answers 404 for a pickup it does not know', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    const response = await getPickupEvents(app, 'pk_nothing');

    assert.equal(response.statusCode, 404);
    assert.equal(response.json().detail, 'Pickup pk_nothing does not exist.');
  });
});
