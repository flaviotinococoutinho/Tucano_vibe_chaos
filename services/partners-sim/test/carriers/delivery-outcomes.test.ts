import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  carriersApp,
  getPickup,
  OWN_FLEET_PICKUP,
  postPickup,
  putChaos,
} from '../support/carriers.ts';
import { sequence } from '../support/random.ts';
import { WebhookReceiver } from '../support/receiver.ts';

type ParcelEvent = {
  readonly type: string;
  readonly data: { readonly attempt?: number; readonly reason?: string };
};

function eventsOf(webhooks: readonly { readonly body: string }[]): ParcelEvent[] {
  return webhooks.map(({ body }) => JSON.parse(body) as ParcelEvent);
}

describe('a failed visit', () => {
  it('is tried again, up to three visits, then the parcels return', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    // A fixed roll below 0.5 always lands in the failure band once failureRate is 1: every
    // visit fails the same way (recipient_absent), regardless of how many times it is asked.
    const app = carriersApp({ webhookUrl: receiver.url, random: () => 0.1 });
    t.after(() => app.close());
    await putChaos(app, { failureRate: 1 });

    const created = await postPickup(app, { body: OWN_FLEET_PICKUP });
    const events = eventsOf(await receiver.waitFor(9));

    assert.deepEqual(
      events.map((event) => event.type),
      [
        'parcel.picked_up',
        'parcel.out_for_delivery',
        'parcel.delivery_failed',
        'parcel.out_for_delivery',
        'parcel.delivery_failed',
        'parcel.out_for_delivery',
        'parcel.delivery_failed',
        'parcel.returning',
        'parcel.returned',
      ],
    );
    assert.deepEqual(
      events.filter((event) => event.type === 'parcel.out_for_delivery').map((e) => e.data.attempt),
      [1, 2, 3],
    );
    assert.deepEqual(
      events.filter((event) => event.type === 'parcel.delivery_failed').map((e) => e.data.attempt),
      [1, 2, 3],
    );
    assert.ok(
      events.every(
        (event) =>
          event.type !== 'parcel.delivery_failed' || event.data.reason === 'recipient_absent',
      ),
    );

    const pickup = (await getPickup(app, created.json().id)).json();
    assert.equal(pickup.status, 'returned');
    assert.equal(pickup.attempts, 3);
  });

  it('is tried again after a failure that is not a refusal, and can still be delivered', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    // Own fleet, no hubs: sleepStep, sleepStep, outcome(fail), sleepStep, sleepStep,
    // outcome(deliver), sleepStep, then the delivered step draws a name and a masked CPF
    // (11 more rolls). The extra padding covers those without having to count them by hand.
    const rolls = [0.1, 0.1, 0.1, 0.1, 0.1, 0.9, 0.1, ...Array.from({ length: 20 }, () => 0.1)];
    const app = carriersApp({ webhookUrl: receiver.url, random: sequence(...rolls) });
    t.after(() => app.close());
    await putChaos(app, { failureRate: 0.5 });

    await postPickup(app, { body: OWN_FLEET_PICKUP });
    const events = eventsOf(await receiver.waitFor(5));

    assert.deepEqual(
      events.map((event) => event.type),
      [
        'parcel.picked_up',
        'parcel.out_for_delivery',
        'parcel.delivery_failed',
        'parcel.out_for_delivery',
        'parcel.delivered',
      ],
    );
    assert.equal(events[2]?.data.reason, 'recipient_absent');
    assert.deepEqual(
      events.filter((event) => event.data.attempt !== undefined).map((e) => e.data.attempt),
      [1, 1, 2, 2],
    );
  });
});

describe('a refusal', () => {
  it('sends the parcels back right away, without trying again', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    // Any roll strikes once refusalRate is 1, so a fixed random is enough here too.
    const app = carriersApp({ webhookUrl: receiver.url, random: () => 0.1 });
    t.after(() => app.close());
    await putChaos(app, { refusalRate: 1 });

    await postPickup(app, { body: OWN_FLEET_PICKUP });
    const events = eventsOf(await receiver.waitFor(5));

    assert.deepEqual(
      events.map((event) => event.type),
      [
        'parcel.picked_up',
        'parcel.out_for_delivery',
        'parcel.delivery_failed',
        'parcel.returning',
        'parcel.returned',
      ],
    );
    assert.equal(events[2]?.data.reason, 'recipient_refused');
    assert.equal(events[1]?.data.attempt, 1);
    assert.equal(events[2]?.data.attempt, 1);
  });
});
