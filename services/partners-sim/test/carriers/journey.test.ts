import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { verify } from '../../src/webhooks/signature.ts';
import {
  carriersApp,
  EVENT_ID,
  type LogLine,
  OWN_FLEET_PICKUP,
  PICKUP,
  postPickup,
  putChaos,
  SECRET,
} from '../support/carriers.ts';
import { InstantClock } from '../support/clock.ts';
import { eventually } from '../support/eventually.ts';
import type { ReceivedWebhook } from '../support/receiver.ts';
import { WebhookReceiver } from '../support/receiver.ts';

type ParcelEvent = {
  readonly id: string;
  readonly type: string;
  readonly createdAt: string;
  readonly data: {
    readonly pickupId: string;
    readonly carrier: string;
    readonly reference: string;
    readonly trackingCode: string;
    readonly hub?: string;
    readonly attempt?: number;
    readonly receiverName?: string;
    readonly receiverDocument?: string;
    readonly reason?: string;
  };
};

function eventsOf(webhooks: readonly ReceivedWebhook[]): ParcelEvent[] {
  return webhooks.map(({ body }) => JSON.parse(body) as ParcelEvent);
}

function typesOf(events: readonly ParcelEvent[]): string[] {
  return events.map((event) => event.type);
}

describe('the own fleet', () => {
  it('delivers inside the state of its fulfillment center, with no hub scans', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const clock = new InstantClock();
    const app = carriersApp({ webhookUrl: receiver.url, clock });
    t.after(() => app.close());

    const created = await postPickup(app, {
      body: OWN_FLEET_PICKUP,
      headers: { 'x-correlation-id': 'req-9#1' },
    });
    const pickupId = created.json().id;
    const webhooks = await receiver.waitFor(3);
    const [pickedUp, outForDelivery, delivered] = eventsOf(webhooks);

    assert.deepEqual(typesOf(eventsOf(webhooks)), [
      'parcel.picked_up',
      'parcel.out_for_delivery',
      'parcel.delivered',
    ]);
    const common = {
      pickupId,
      carrier: OWN_FLEET_PICKUP.carrier,
      reference: OWN_FLEET_PICKUP.reference,
      trackingCode: OWN_FLEET_PICKUP.trackingCode,
    };
    assert.deepEqual(pickedUp?.data, common);
    assert.deepEqual(outForDelivery?.data, { ...common, attempt: 1 });
    assert.match(String(delivered?.id), EVENT_ID);
    assert.equal(delivered?.data.attempt, 1);
    assert.equal(delivered?.data.pickupId, pickupId);
    const receiverName = String(delivered?.data.receiverName);
    const receiverDocument = String(delivered?.data.receiverDocument);
    assert.ok(receiverName.length > 0 && receiverName.length <= 120);
    assert.ok(receiverDocument.length <= 20);
    assert.match(receiverDocument, /^\d{3}\.\d{3}\.\d{3}-\d{2}$/);

    for (const webhook of webhooks) {
      assert.equal(webhook.headers['content-type'], 'application/json');
      assert.equal(webhook.headers['x-correlation-id'], 'req-9#1');
      const header = String(webhook.headers['carrier-signature']);
      const now = Math.floor(clock.now() / 1000);
      assert.equal(verify({ secret: SECRET, payload: webhook.body, header, now }), 'valid');
    }
  });
});

describe('a partner shipment', () => {
  it('crossing two states passes through both hubs, origin first', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = carriersApp({ webhookUrl: receiver.url });
    t.after(() => app.close());

    await postPickup(app, { body: PICKUP });
    const events = eventsOf(await receiver.waitFor(5));

    assert.deepEqual(typesOf(events), [
      'parcel.picked_up',
      'parcel.hub_scanned',
      'parcel.hub_scanned',
      'parcel.out_for_delivery',
      'parcel.delivered',
    ]);
    assert.equal(events[1]?.data.hub, 'Hub Cajamar (SP)');
    assert.equal(events[2]?.data.hub, 'Hub Contagem (MG)');
  });

  it('staying inside one state passes through that state hub only', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const app = carriersApp({ webhookUrl: receiver.url });
    t.after(() => app.close());
    const sameState = {
      ...PICKUP,
      destination: { city: 'Campinas', state: 'SP', postalCode: '13010001' },
    };

    await postPickup(app, { body: sameState });
    const events = eventsOf(await receiver.waitFor(4));

    assert.deepEqual(typesOf(events), [
      'parcel.picked_up',
      'parcel.hub_scanned',
      'parcel.out_for_delivery',
      'parcel.delivered',
    ]);
    assert.equal(events[1]?.data.hub, 'Hub Cajamar (SP)');
  });
});

describe('event order', () => {
  it('goes out strictly one at a time: the next step waits for the previous event', async (t) => {
    const receiver = await WebhookReceiver.start([500]);
    t.after(() => receiver.close());
    const clock = new InstantClock();
    // A step delay far from any retry delay (1, 2, 4, 8 or 16 s) makes the two easy to tell apart.
    const app = carriersApp({
      webhookUrl: receiver.url,
      clock,
      env: { CARRIERS_STEP_MIN_MS: '500', CARRIERS_STEP_MAX_MS: '500' },
    });
    t.after(() => app.close());

    await postPickup(app, { body: OWN_FLEET_PICKUP });
    const webhooks = await receiver.waitFor(4); // picked_up retried once, out_for_delivery, delivered

    assert.deepEqual(typesOf(eventsOf(webhooks)), [
      'parcel.picked_up',
      'parcel.picked_up',
      'parcel.out_for_delivery',
      'parcel.delivered',
    ]);
    // The retry's backoff (1 s) sits between the first two step delays (500 ms each): the
    // out_for_delivery step never started its own wait until the picked_up webhook settled.
    // The courier's own ride sits between out_for_delivery and delivered: one tick, at the
    // test default COURIER_RIDE_MS (1000 ms), before the delivered step's own step delay.
    assert.deepEqual(clock.sleeps, [500, 1_000, 500, 1_000, 500]);
  });

  it('moves to the next step right away when a webhook is dropped: a drop counts as done', async (t) => {
    const receiver = await WebhookReceiver.start();
    t.after(() => receiver.close());
    const logs: LogLine[] = [];
    const app = carriersApp({ webhookUrl: receiver.url, logs });
    t.after(() => app.close());
    await putChaos(app, { webhooks: { dropRate: 1 } });

    await postPickup(app, { body: OWN_FLEET_PICKUP });
    await eventually(() =>
      logs.some((line) => line.message === 'pickup event' && line.status === 'delivered'),
    );

    assert.equal(receiver.received.length, 0);
  });
});
