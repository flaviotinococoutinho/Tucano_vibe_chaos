import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { verify } from '../../src/webhooks/signature.ts';
import {
  COURIER_REPORT_SECRET,
  carriersApp,
  NOWHERE_TRACKING,
  OWN_FLEET_PICKUP,
  PICKUP,
  postPickup,
  putChaos,
} from '../support/carriers.ts';
import { InstantClock } from '../support/clock.ts';
import type { ReceivedWebhook } from '../support/receiver.ts';
import { WebhookReceiver } from '../support/receiver.ts';

type ParcelEvent = { readonly type: string };
type DeliveryNews = {
  readonly type: string;
  readonly trackingCode?: string;
  readonly remainingMeters?: number;
  readonly outcome?: string;
};

function eventsOf(webhooks: readonly ReceivedWebhook[]): ParcelEvent[] {
  return webhooks.map(({ body }) => JSON.parse(body) as ParcelEvent);
}

function newsOf(webhooks: readonly ReceivedWebhook[]): DeliveryNews[] {
  return webhooks.map(({ body }) => JSON.parse(body) as DeliveryNews);
}

describe("the own fleet's courier device, wired into the journey", () => {
  it('reports positions between out-for-delivery and the outcome, then ended', async (t) => {
    const carrierReceiver = await WebhookReceiver.start();
    t.after(() => carrierReceiver.close());
    const trackingReceiver = await WebhookReceiver.start();
    t.after(() => trackingReceiver.close());
    const clock = new InstantClock();
    const app = carriersApp({
      webhookUrl: carrierReceiver.url,
      clock,
      env: {
        TRACKING_URL: trackingReceiver.url,
        COURIER_POSITION_INTERVAL_MS: '1000',
        COURIER_RIDE_MS: '3000',
      },
    });
    t.after(() => app.close());

    await postPickup(app, { body: OWN_FLEET_PICKUP, headers: { 'x-correlation-id': 'req-9#1' } });
    await carrierReceiver.waitFor(3); // picked_up, out_for_delivery, delivered
    const news = newsOf(await trackingReceiver.waitFor(4)); // 3 positions, then ended

    assert.deepEqual(
      news.map((item) => item.type),
      ['position', 'position', 'position', 'ended'],
    );
    assert.ok(
      news.slice(0, 3).every((item) => item.trackingCode === OWN_FLEET_PICKUP.trackingCode),
    );
    const remaining = news.slice(0, 3).map((item) => item.remainingMeters as number);
    assert.equal(remaining.at(-1), 0, 'the last position of the ride is at the door');
    assert.deepEqual(
      [...remaining].sort((a, b) => b - a),
      remaining,
      'each position is no farther than the last',
    );
    assert.equal(news[3]?.outcome, 'delivered');

    for (const webhook of trackingReceiver.received) {
      assert.equal(webhook.headers['content-type'], 'application/json');
      assert.equal(webhook.headers['x-correlation-id'], 'req-9#1');
      const header = String(webhook.headers['courier-signature']);
      const now = Math.floor(clock.now() / 1000);
      assert.equal(
        verify({ secret: COURIER_REPORT_SECRET, payload: webhook.body, header, now }),
        'valid',
      );
    }
  });

  it('rides again for every visit a failed delivery makes again', async (t) => {
    const carrierReceiver = await WebhookReceiver.start();
    t.after(() => carrierReceiver.close());
    const trackingReceiver = await WebhookReceiver.start();
    t.after(() => trackingReceiver.close());
    // A fixed roll below 0.5 always lands in the failure band once failureRate is 1: every
    // one of the three visits fails, and none of them consumes a random sequence the device
    // could disturb by drawing its own jitter along the way.
    const app = carriersApp({
      webhookUrl: carrierReceiver.url,
      random: () => 0.1,
      env: { TRACKING_URL: trackingReceiver.url },
    });
    t.after(() => app.close());
    await putChaos(app, { failureRate: 1 });

    await postPickup(app, { body: OWN_FLEET_PICKUP });
    await carrierReceiver.waitFor(9); // picked_up, 3x(out_for_delivery, delivery_failed), returning, returned
    const news = newsOf(await trackingReceiver.waitFor(6)); // 3 rides, one position and one ended each

    assert.deepEqual(
      news.map((item) => item.type),
      ['position', 'ended', 'position', 'ended', 'position', 'ended'],
    );
    assert.deepEqual(
      news.filter((item) => item.type === 'ended').map((item) => item.outcome),
      ['delivery_failed', 'delivery_failed', 'delivery_failed'],
    );
  });

  it('reports nothing at all for a partner shipment', async (t) => {
    const carrierReceiver = await WebhookReceiver.start();
    t.after(() => carrierReceiver.close());
    const trackingReceiver = await WebhookReceiver.start();
    t.after(() => trackingReceiver.close());
    const app = carriersApp({
      webhookUrl: carrierReceiver.url,
      env: { TRACKING_URL: trackingReceiver.url },
    });
    t.after(() => app.close());

    await postPickup(app, { body: PICKUP });
    await carrierReceiver.waitFor(5); // picked_up, hub_scanned x2, out_for_delivery, delivered

    assert.equal(trackingReceiver.received.length, 0);
  });

  describe('a tracking service that is down', () => {
    it('refuses the connection, and the journey still finishes with its own webhooks', async (t) => {
      const carrierReceiver = await WebhookReceiver.start();
      t.after(() => carrierReceiver.close());
      const app = carriersApp({
        webhookUrl: carrierReceiver.url,
        env: { TRACKING_URL: NOWHERE_TRACKING },
      });
      t.after(() => app.close());

      await postPickup(app, { body: OWN_FLEET_PICKUP });
      const events = eventsOf(await carrierReceiver.waitFor(3));

      assert.deepEqual(
        events.map((event) => event.type),
        ['parcel.picked_up', 'parcel.out_for_delivery', 'parcel.delivered'],
      );
    });

    it('answers 500, and the journey still finishes with its own webhooks', async (t) => {
      const carrierReceiver = await WebhookReceiver.start();
      t.after(() => carrierReceiver.close());
      // One tick's worth of the ride (the test default) plus the ended report: two reports,
      // both answered 500, so a tracking service that is entirely down never once succeeds.
      const trackingReceiver = await WebhookReceiver.start([500, 500]);
      t.after(() => trackingReceiver.close());
      const app = carriersApp({
        webhookUrl: carrierReceiver.url,
        env: { TRACKING_URL: trackingReceiver.url },
      });
      t.after(() => app.close());

      await postPickup(app, { body: OWN_FLEET_PICKUP });
      const events = eventsOf(await carrierReceiver.waitFor(3));
      // The ended report follows the delivered webhook, so waiting for the carrier's 3rd
      // webhook alone would race it: wait for tracking's own 2nd report before counting it.
      await trackingReceiver.waitFor(2);

      assert.deepEqual(
        events.map((event) => event.type),
        ['parcel.picked_up', 'parcel.out_for_delivery', 'parcel.delivered'],
      );
      assert.equal(trackingReceiver.received.length, 2);
    });
  });
});
