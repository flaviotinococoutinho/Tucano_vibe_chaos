import assert from 'node:assert/strict';
import { describe, it, type TestContext } from 'node:test';
import Fastify from 'fastify';
import type { Clock } from '../../src/clock.ts';
import { systemClock } from '../../src/clock.ts';
import { loadConfig } from '../../src/config.ts';
import { CourierDevice, type CourierPickup } from '../../src/couriers/device.ts';
import { haversineMeters, pointAt, routeFor } from '../../src/couriers/route.ts';
import { logOptions } from '../../src/platform/logging.ts';
import type { Origin } from '../../src/webhooks/sender.ts';
import { verify } from '../../src/webhooks/signature.ts';
import { InstantClock } from '../support/clock.ts';
import type { LogLine } from '../support/logs.ts';
import { type Answer, WebhookReceiver } from '../support/receiver.ts';

const SECRET = 'whsec_test_couriers';

const PICKUP: CourierPickup = {
  id: 'pk_01J8Z5W3Q4X9M2N7B8C6D5E4F3',
  trackingCode: 'TX02Q6AGJQ45G00',
  origin: { center: 'GRU1' },
};

type Setup = {
  readonly answers?: readonly Answer[];
  readonly clock?: Clock;
  readonly positionIntervalMs?: number;
  readonly rideMs?: number;
  readonly logLevel?: string;
};

async function deviceFor(
  t: TestContext,
  {
    answers,
    clock = new InstantClock(),
    positionIntervalMs = 1000,
    rideMs = 3000,
    logLevel = 'info',
  }: Setup = {},
) {
  const receiver = await WebhookReceiver.start(answers);
  t.after(() => receiver.close());
  const shutdown = new AbortController();
  const logs: LogLine[] = [];
  const logger = logOptions(loadConfig({ LOG_LEVEL: logLevel }), {
    write: (line) => logs.push(JSON.parse(line)),
  });
  // The logger of the request that triggered the ride, like request.log in the app.
  const log = Fastify({ logger }).log.child({ correlation_id: 'req-9#1' });
  const origin: Origin = { log, correlationId: 'req-9#1' };
  const device = new CourierDevice({
    url: receiver.url,
    secret: SECRET,
    positionIntervalMs,
    rideMs,
    clock,
    random: () => 0.5,
    signal: shutdown.signal,
  });

  return { receiver, logs, shutdown, device, origin, clock };
}

function newsOf(webhooks: readonly { readonly body: string }[]): Record<string, unknown>[] {
  return webhooks.map(({ body }) => JSON.parse(body) as Record<string, unknown>);
}

describe('the courier device', () => {
  describe('ride', () => {
    it('reports one position per tick, signed and verifiable with the given secret', async (t) => {
      const clock = new InstantClock();
      const { receiver, device, origin } = await deviceFor(t, {
        clock,
        rideMs: 3000,
        positionIntervalMs: 1000,
      });

      await device.ride(PICKUP, origin);

      assert.equal(receiver.received.length, 3);
      const news = newsOf(receiver.received);
      assert.ok(news.every((item) => item.type === 'position'));
      assert.ok(news.every((item) => item.trackingCode === PICKUP.trackingCode));
      for (const webhook of receiver.received) {
        assert.equal(webhook.headers['content-type'], 'application/json');
        assert.equal(webhook.headers['x-correlation-id'], 'req-9#1');
        const header = String(webhook.headers['courier-signature']);
        const now = Math.floor(clock.now() / 1000);
        assert.equal(verify({ secret: SECRET, payload: webhook.body, header, now }), 'valid');
      }
    });

    it('refuses to verify with the wrong secret', async (t) => {
      const clock = new InstantClock();
      const { receiver, device, origin } = await deviceFor(t, { clock, rideMs: 1000 });

      await device.ride(PICKUP, origin);

      const [webhook] = receiver.received;
      assert.ok(webhook);
      const header = String(webhook.headers['courier-signature']);
      const now = Math.floor(clock.now() / 1000);
      assert.equal(
        verify({ secret: 'whsec_someone_else', payload: webhook.body, header, now }),
        'mismatch',
      );
    });

    it('brings remainingMeters down to zero exactly at the door', async (t) => {
      const { device, receiver, origin } = await deviceFor(t, {
        rideMs: 4000,
        positionIntervalMs: 1000,
      });

      await device.ride(PICKUP, origin);

      const remaining = newsOf(receiver.received).map((item) => item.remainingMeters as number);
      assert.equal(remaining.length, 4);
      assert.ok(remaining.every((value) => Number.isInteger(value) && value >= 0));
      assert.equal(remaining.at(-1), 0);
      assert.deepEqual(
        [...remaining].sort((a, b) => b - a),
        remaining,
        'each report should be no farther than the last',
      );
    });

    it('rounds latitude and longitude to 6 decimals', async (t) => {
      const { device, receiver, origin } = await deviceFor(t, {
        rideMs: 1000,
        positionIntervalMs: 1000,
      });

      await device.ride(PICKUP, origin);

      const [position] = newsOf(receiver.received);
      const latitude = position?.latitude as number;
      const longitude = position?.longitude as number;
      assert.equal(latitude, Number(latitude.toFixed(6)));
      assert.equal(longitude, Number(longitude.toFixed(6)));
    });

    it('jitters the reported point by a few meters from the ideal one on the curve', async (t) => {
      const { device, receiver, origin } = await deviceFor(t, {
        rideMs: 1000,
        positionIntervalMs: 1000,
      });
      const route = routeFor(PICKUP.origin.center, PICKUP.trackingCode);
      const ideal = pointAt(route, 1);

      await device.ride(PICKUP, origin);

      const [position] = newsOf(receiver.received);
      const reported = {
        latitude: position?.latitude as number,
        longitude: position?.longitude as number,
      };
      const jitterMeters = haversineMeters(ideal, reported);
      assert.ok(
        jitterMeters > 0 && jitterMeters <= 6,
        `expected a few meters of jitter, got ${jitterMeters}`,
      );
    });

    it('tries a report once and never retries it, even when the answer is not 2xx', async (t) => {
      const { device, receiver, origin } = await deviceFor(t, {
        answers: [500],
        rideMs: 1000,
        positionIntervalMs: 1000,
      });

      await device.ride(PICKUP, origin);

      assert.equal(receiver.received.length, 1);
    });

    it('never stops the ride when every report fails: each tick still gets its turn', async (t) => {
      const { device, receiver, origin } = await deviceFor(t, {
        answers: [500, 500, 500],
        rideMs: 3000,
        positionIntervalMs: 1000,
      });

      await device.ride(PICKUP, origin);

      assert.equal(receiver.received.length, 3);
    });

    it('logs only the first report failure of a ride at the default level', async (t) => {
      const { device, logs, origin } = await deviceFor(t, {
        answers: [500, 500, 500],
        rideMs: 3000,
        positionIntervalMs: 1000,
      });

      await device.ride(PICKUP, origin);

      const failures = logs.filter((line) => line.message === 'courier report failed');
      // The other two failures were logged below 'info' (at debug) and never reached this stream.
      assert.equal(failures.length, 1);
      assert.equal(failures[0]?.level, 'warn');
      assert.equal(failures[0]?.pickupId, PICKUP.id);
    });

    it('logs every report failure at debug once the logger reaches that level, the first still at warn', async (t) => {
      const { device, logs, origin } = await deviceFor(t, {
        answers: [500, 500, 500],
        rideMs: 3000,
        positionIntervalMs: 1000,
        logLevel: 'debug',
      });

      await device.ride(PICKUP, origin);

      const failures = logs.filter((line) => line.message === 'courier report failed');
      assert.equal(failures.length, 3);
      assert.equal(failures[0]?.level, 'warn');
      assert.ok(failures.slice(1).every((line) => line.level === 'debug'));
    });

    it('starts a fresh warning budget for the next ride of the same pickup', async (t) => {
      const { device, logs, origin } = await deviceFor(t, {
        answers: [500, 500],
        rideMs: 1000,
        positionIntervalMs: 1000,
        logLevel: 'debug',
      });

      await device.ride(PICKUP, origin);
      await device.ride(PICKUP, origin);

      const failures = logs.filter((line) => line.message === 'courier report failed');
      assert.deepEqual(
        failures.map((line) => line.level),
        ['warn', 'warn'],
      );
    });

    it('stops at once when the server shuts down', async (t) => {
      const { device, receiver, shutdown, origin } = await deviceFor(t, {
        clock: systemClock,
        rideMs: 600,
        positionIntervalMs: 200,
      });

      const ride = device.ride(PICKUP, origin);
      await receiver.waitFor(1);
      shutdown.abort();

      await assert.rejects(ride, { name: 'AbortError' });
      assert.ok(receiver.received.length < 3);
    });
  });

  describe('end', () => {
    it('reports ended with the outcome, signed the same way as a position', async (t) => {
      const clock = new InstantClock();
      const { receiver, device, origin } = await deviceFor(t, { clock });

      await device.end(PICKUP, 'delivered', origin);

      assert.equal(receiver.received.length, 1);
      const [news] = newsOf(receiver.received);
      assert.deepEqual(news, {
        type: 'ended',
        trackingCode: PICKUP.trackingCode,
        outcome: 'delivered',
        at: new Date(clock.now()).toISOString(),
      });
      const [webhook] = receiver.received;
      assert.ok(webhook);
      const header = String(webhook.headers['courier-signature']);
      const now = Math.floor(clock.now() / 1000);
      assert.equal(verify({ secret: SECRET, payload: webhook.body, header, now }), 'valid');
    });

    it('carries a failed outcome the same way', async (t) => {
      const { receiver, device, origin } = await deviceFor(t);

      await device.end(PICKUP, 'delivery_failed', origin);

      assert.equal(newsOf(receiver.received)[0]?.outcome, 'delivery_failed');
    });

    it('does not stop the journey when the report fails', async (t) => {
      const { receiver, device, origin } = await deviceFor(t, { answers: [500] });

      await assert.doesNotReject(device.end(PICKUP, 'delivered', origin));

      assert.equal(receiver.received.length, 1);
    });
  });
});
