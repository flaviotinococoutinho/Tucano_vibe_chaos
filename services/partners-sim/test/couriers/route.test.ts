import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
  centerLocation,
  haversineMeters,
  pointAt,
  remainingMetersAt,
  routeFor,
} from '../../src/couriers/route.ts';

const GRU1 = { latitude: -23.4356, longitude: -46.4731 };
const BHZ1 = { latitude: -19.9321, longitude: -44.0539 };

describe('the fulfillment center a route starts from', () => {
  it('is exact for a center the lab knows', () => {
    assert.deepEqual(centerLocation('GRU1'), GRU1);
    assert.deepEqual(centerLocation('BHZ1'), BHZ1);
  });

  it('falls back to BHZ1 for a center the lab does not know', () => {
    assert.deepEqual(centerLocation('POA1'), BHZ1);
  });
});

describe('the route of a tracking code', () => {
  it('always starts at the fulfillment center', () => {
    const route = routeFor('GRU1', 'TX02Q6AGJQ45G00');

    assert.deepEqual(route.origin, GRU1);
  });

  it('picks a door between 2.5 and 7.5 km from the center', () => {
    const codes = ['TX02Q6AGJQ45G00', 'TX9ZH1M3K7P2D4X6', 'TXA0B1C2D3E4F5G6', 'TX00000000000000'];

    for (const trackingCode of codes) {
      const route = routeFor('GRU1', trackingCode);
      const distance = haversineMeters(route.origin, route.destination);
      assert.ok(
        distance >= 2_500 && distance < 7_500,
        `expected ${trackingCode} to land between 2500 and 7500 m, got ${distance}`,
      );
    }
  });

  it('is deterministic: the same center and tracking code always ride the same path', () => {
    const first = routeFor('GRU1', 'TX02Q6AGJQ45G00');
    const second = routeFor('GRU1', 'TX02Q6AGJQ45G00');

    assert.deepEqual(first, second);
  });

  it('rides a different path for a different tracking code', () => {
    const first = routeFor('GRU1', 'TX02Q6AGJQ45G00');
    const second = routeFor('GRU1', 'TX9ZH1M3K7P2D4X6');

    assert.notDeepEqual(first.destination, second.destination);
  });

  it('bulges the control point away from the straight line, roughly at its middle', () => {
    const route = routeFor('GRU1', 'TX02Q6AGJQ45G00');
    const straight = haversineMeters(route.origin, route.destination);
    const toControl = haversineMeters(route.origin, route.control);
    const fromControl = haversineMeters(route.control, route.destination);

    // Perpendicular at the middle, offset by 20% of the distance: both legs of the bulge sit
    // close to half the straight distance, a little longer because of the offset itself.
    assert.ok(toControl > straight * 0.4 && toControl < straight * 0.7);
    assert.ok(fromControl > straight * 0.4 && fromControl < straight * 0.7);
  });
});

describe('a point along the route', () => {
  it('is the fulfillment center at t=0 and the door at t=1', () => {
    const route = routeFor('BHZ1', 'TX02Q6AGJQ45G00');

    assert.deepEqual(pointAt(route, 0), route.origin);
    assert.deepEqual(pointAt(route, 1), route.destination);
  });

  it('clamps t outside the 0..1 range instead of extrapolating past the route', () => {
    const route = routeFor('BHZ1', 'TX02Q6AGJQ45G00');

    assert.deepEqual(pointAt(route, -1), route.origin);
    assert.deepEqual(pointAt(route, 2), route.destination);
  });
});

describe('the meters left on the route', () => {
  it('is zero exactly at the door', () => {
    const route = routeFor('GRU1', 'TX02Q6AGJQ45G00');

    assert.equal(remainingMetersAt(route, 1), 0);
  });

  it('is never negative and always a whole number', () => {
    const route = routeFor('GRU1', 'TX02Q6AGJQ45G00');

    for (const t of [0, 0.1, 0.5, 0.9, 1]) {
      const remaining = remainingMetersAt(route, t);
      assert.ok(Number.isInteger(remaining));
      assert.ok(remaining >= 0);
    }
  });

  it('goes down as the courier gets closer to the door', () => {
    const route = routeFor('GRU1', 'TX02Q6AGJQ45G00');
    const samples = [0, 0.25, 0.5, 0.75, 1].map((t) => remainingMetersAt(route, t));

    assert.deepEqual(
      [...samples].sort((a, b) => b - a),
      samples,
    );
  });

  it('roughly matches the whole curve at the start: a bit more than the straight distance', () => {
    const route = routeFor('GRU1', 'TX02Q6AGJQ45G00');
    const straight = haversineMeters(route.origin, route.destination);
    const whole = remainingMetersAt(route, 0);

    // The curve bulges, so it is longer than the straight line, but the bulge is only 20%
    // of the distance: nowhere near double it.
    assert.ok(whole > straight);
    assert.ok(whole < straight * 1.5);
  });
});
