import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { carriersApp, type LogLine, PICKUP, PICKUP_ID, postPickup } from '../support/carriers.ts';
import { InstantClock } from '../support/clock.ts';

describe('booking a pickup', () => {
  it('accepts it as scheduled and answers 201 with it', async (t) => {
    const logs: LogLine[] = [];
    const app = carriersApp({ logs });
    t.after(() => app.close());

    const response = await postPickup(app, { headers: { 'x-correlation-id': 'req-1#1' } });

    assert.equal(response.statusCode, 201);
    assert.equal(response.headers['idempotent-replayed'], undefined);
    const pickup = response.json();
    assert.match(pickup.id, PICKUP_ID);
    assert.deepEqual(pickup, {
      id: pickup.id,
      carrier: PICKUP.carrier,
      reference: PICKUP.reference,
      trackingCode: PICKUP.trackingCode,
      status: 'scheduled',
      attempts: 0,
      createdAt: '2026-09-27T12:00:00.000Z',
    });
    assert.partialDeepStrictEqual(
      logs.find((line) => line.message === 'pickup booked'),
      {
        pickupId: pickup.id,
        reference: PICKUP.reference,
        carrier: PICKUP.carrier,
        correlation_id: 'req-1#1',
      },
    );
  });

  it('refuses a body it cannot book, and names the field', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    const { origin, destination } = PICKUP;
    const cases: [object, Record<string, string[]>][] = [
      [{ reference: PICKUP.reference }, { carrier: ["must have required property 'carrier'"] }],
      [
        { ...PICKUP, trackingCode: undefined },
        { trackingCode: ["must have required property 'trackingCode'"] },
      ],
      [
        { ...PICKUP, carrier: 'correios' },
        { carrier: ['must be equal to one of the allowed values'] },
      ],
      [
        { ...PICKUP, trackingCode: 'ABC123' },
        { trackingCode: ['must match pattern "^TX[0-9A-HJKMNP-TV-Z]{13}$"'] },
      ],
      [{ ...PICKUP, reference: '' }, { reference: ['must NOT have fewer than 1 characters'] }],
      [
        { ...PICKUP, origin: { ...origin, state: 'sp' } },
        { 'origin.state': ['must match pattern "^[A-Z]{2}$"'] },
      ],
      [
        { ...PICKUP, origin: { ...origin, center: 'GRU' } },
        { 'origin.center': ['must match pattern "^[A-Z]{3}[0-9]$"'] },
      ],
      [
        { ...PICKUP, destination: { ...destination, postalCode: '123' } },
        { 'destination.postalCode': ['must match pattern "^[0-9]{8}$"'] },
      ],
      [{ ...PICKUP, parcels: 0 }, { parcels: ['must be >= 1'] }],
      [{ ...PICKUP, weightGrams: 0 }, { weightGrams: ['must be >= 1'] }],
      [
        { ...PICKUP, labelUrl: 'https://example.com' },
        { body: ['must NOT have additional properties'] },
      ],
    ];

    for (const [body, errors] of cases) {
      const response = await postPickup(app, { body });

      assert.equal(response.statusCode, 422, JSON.stringify(body));
      assert.partialDeepStrictEqual(response.json(), { title: 'Unprocessable Content', errors });
    }
  });

  it('requires the key, and checks it before the body', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    for (const headers of [{}, { 'idempotency-key': '  ' }]) {
      const response = await app.inject({
        method: 'POST',
        url: '/carriers/v1/pickups',
        headers: { 'x-correlation-id': 'req-2#1', ...headers },
        payload: { carrier: 'not-a-real-shape' },
      });

      assert.equal(response.statusCode, 400);
      assert.deepEqual(response.json(), {
        type: 'about:blank',
        title: 'Bad Request',
        status: 400,
        detail: 'The Idempotency-Key header is required.',
        instance: '/carriers/v1/pickups',
        correlationId: 'req-2#1',
      });
    }
  });
});

describe('idempotency', () => {
  it('answers the same key and body with the first answer, marked as a replay', async (t) => {
    const logs: LogLine[] = [];
    const app = carriersApp({ logs });
    t.after(() => app.close());

    const first = await postPickup(app);
    const again = await postPickup(app);

    assert.equal(again.statusCode, 201);
    assert.equal(again.headers['idempotent-replayed'], 'true');
    assert.equal(first.headers['idempotent-replayed'], undefined);
    assert.deepEqual(again.json(), first.json());
    assert.equal(logs.filter((line) => line.message === 'pickup booked').length, 1);
  });

  it('matches bodies whatever the order of their fields', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    const reordered = {
      weightGrams: PICKUP.weightGrams,
      parcels: PICKUP.parcels,
      destination: PICKUP.destination,
      origin: PICKUP.origin,
      trackingCode: PICKUP.trackingCode,
      reference: PICKUP.reference,
      carrier: PICKUP.carrier,
    };

    const first = await postPickup(app);
    const again = await postPickup(app, { body: reordered });

    assert.equal(again.headers['idempotent-replayed'], 'true');
    assert.equal(again.json().id, first.json().id);
  });

  it('refuses the same key with another body', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());
    await postPickup(app);

    const response = await postPickup(app, { body: { ...PICKUP, parcels: 2 } });

    assert.equal(response.statusCode, 422);
    assert.partialDeepStrictEqual(response.json(), {
      title: 'Unprocessable Content',
      detail: 'The Idempotency-Key "shp-1" was already used with a different request.',
    });
  });

  it('refuses a key longer than 255 characters', async (t) => {
    const app = carriersApp();
    t.after(() => app.close());

    const response = await postPickup(app, { key: 'k'.repeat(256) });

    assert.equal(response.statusCode, 400);
    assert.partialDeepStrictEqual(response.json(), {
      detail: 'The Idempotency-Key header must have at most 255 characters.',
    });
  });

  it('forgets a key after 24 hours', async (t) => {
    const clock = new InstantClock();
    const app = carriersApp({ clock });
    t.after(() => app.close());
    const first = await postPickup(app);

    clock.advance(24 * 60 * 60 * 1000);
    const later = await postPickup(app);

    assert.equal(later.statusCode, 201);
    assert.equal(later.headers['idempotent-replayed'], undefined);
    assert.notEqual(later.json().id, first.json().id);
  });
});
